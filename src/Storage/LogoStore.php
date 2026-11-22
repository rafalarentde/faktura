<?php
declare(strict_types=1);

namespace App\Storage;

use App\Domain\ValidationException;

/**
 * Company logos on disk, outside public/ (served only by GET /company/logo).
 *
 * Only PNG and JPEG are accepted (SVG would be an XSS vector). The type comes from
 * getimagesize() on the file content, never from the client's MIME type or file name. The image is
 * decoded and re-encoded with GD, which drops EXIF, comments, text chunks and any appended
 * payload. The stored name is always logos/company-<id>.<png|jpg>, built from the context
 * company id, so nothing from the client reaches the path.
 */
final class LogoStore
{
    public const MAX_BYTES = 1_048_576;
    public const MAX_PIXELS = 2000;
    private const EXT = [IMAGETYPE_PNG => 'png', IMAGETYPE_JPEG => 'jpg'];
    private const MIME = ['png' => 'image/png', 'jpg' => 'image/jpeg'];

    public function __construct(private string $storagePath)
    {
    }

    /** Relative path (as kept in companies.logo_path) of the logo of $companyId with extension $ext. */
    public static function relativePath(int $companyId, string $ext): string
    {
        return 'logos/company-' . $companyId . '.' . $ext;
    }

    /** True when $path is exactly the path this class generates for $companyId. */
    public static function isOwnPath(int $companyId, string $path): bool
    {
        return in_array($path, [self::relativePath($companyId, 'png'), self::relativePath($companyId, 'jpg')], true);
    }

    public static function mimeFor(string $path): string
    {
        return self::MIME[pathinfo($path, PATHINFO_EXTENSION)] ?? 'application/octet-stream';
    }

    /**
     * Validates and re-encodes the image at $file; returns the clean bytes and the extension.
     *
     * @return array{bytes: string, ext: string}
     * @throws ValidationException field `logo`
     */
    public function process(string $file): array
    {
        $size = @filesize($file);
        if ($size === false || $size === 0) {
            throw $this->error('settings.err_logo_invalid');
        }
        if ($size > self::MAX_BYTES) {
            throw $this->error('settings.err_logo_size');
        }
        $info = @getimagesize($file);
        if ($info === false || !isset(self::EXT[$info[2]])) {
            throw $this->error('settings.err_logo_invalid');
        }
        if ($info[0] > self::MAX_PIXELS || $info[1] > self::MAX_PIXELS || $info[0] < 1 || $info[1] < 1) {
            throw $this->error('settings.err_logo_dimensions');
        }
        $type = $info[2];
        $image = $type === IMAGETYPE_PNG ? @imagecreatefrompng($file) : @imagecreatefromjpeg($file);
        if ($image === false) {
            throw $this->error('settings.err_logo_invalid');
        }
        ob_start();
        if ($type === IMAGETYPE_PNG) {
            imagealphablending($image, false);
            imagesavealpha($image, true);
            $ok = imagepng($image, null, 9);
        } else {
            $ok = imagejpeg($image, null, 90);
        }
        $bytes = (string) ob_get_clean();
        imagedestroy($image);
        if (!$ok || $bytes === '') {
            throw $this->error('settings.err_logo_invalid');
        }
        return ['bytes' => $bytes, 'ext' => self::EXT[$type]];
    }

    /**
     * Writes the bytes as the company's logo (removing a logo with the other extension) and
     * returns the relative path to store in the database.
     */
    public function save(int $companyId, string $bytes, string $ext): string
    {
        if (!in_array($ext, ['png', 'jpg'], true)) {
            throw new \InvalidArgumentException('Unsupported logo extension.');
        }
        $dir = $this->storagePath . '/logos';
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('Cannot create the logo directory.');
        }
        $tmp = $dir . '/.upload-' . bin2hex(random_bytes(8));
        if (file_put_contents($tmp, $bytes) === false) {
            throw new \RuntimeException('Cannot write the logo.');
        }
        @chmod($tmp, 0644);
        $rel = self::relativePath($companyId, $ext);
        if (!rename($tmp, $this->storagePath . '/' . $rel)) {
            @unlink($tmp);
            throw new \RuntimeException('Cannot store the logo.');
        }
        $other = self::relativePath($companyId, $ext === 'png' ? 'jpg' : 'png');
        @unlink($this->storagePath . '/' . $other);
        return $rel;
    }

    /** Deletes the company's logo files (both extensions). */
    public function delete(int $companyId): void
    {
        foreach (['png', 'jpg'] as $ext) {
            @unlink($this->storagePath . '/' . self::relativePath($companyId, $ext));
        }
    }

    /** Absolute file path of a stored logo, or null when $relative is not this company's own path or the file is missing. */
    public function locate(int $companyId, ?string $relative): ?string
    {
        if ($relative === null || !self::isOwnPath($companyId, $relative)) {
            return null;
        }
        $file = $this->storagePath . '/' . $relative;
        return is_file($file) ? $file : null;
    }

    private function error(string $key): ValidationException
    {
        return new ValidationException(['logo' => $key]);
    }
}
