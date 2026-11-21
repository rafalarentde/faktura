<?php
declare(strict_types=1);

namespace App\Core;

/** HTTP response value; App::handle() returns one and only send() writes to the client. */
final class Response
{
    /**
     * @param array<string, string> $headers
     * @param string|null $file a temp file streamed as the body by send() and deleted afterwards
     *                          (never loaded into memory); $body is then empty
     */
    public function __construct(
        public string $body = '',
        public int $status = 200,
        public array $headers = [],
        public ?string $file = null,
    ) {
    }

    /**
     * A download of a temp file: send() streams it with readfile() and unlinks it in a finally,
     * even when the client goes away. Content-Length is set from the file size.
     *
     * @param array<string, string> $headers
     */
    public static function tempFile(string $path, array $headers = []): self
    {
        $size = filesize($path);
        if ($size === false) {
            throw new \RuntimeException('Cannot read the download file.');
        }
        return new self('', 200, $headers + ['Content-Length' => (string) $size], $path);
    }

    public static function redirect(string $to, int $status = 302): self
    {
        if (preg_match('/[\x00-\x1f\x7f]/', $to) === 1) {
            throw new \InvalidArgumentException('Invalid redirect target.');
        }
        return new self('', $status, ['Location' => $to]);
    }

    public static function html(string $body, int $status = 200): self
    {
        return new self($body, $status, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    /** @param array<mixed> $data */
    public static function json(array $data, int $status = 200): self
    {
        return new self(
            json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $status,
            ['Content-Type' => 'application/json']
        );
    }

    public function withHeader(string $name, string $value): self
    {
        if (preg_match('/[\x00-\x1f\x7f]/', $name . $value) === 1) {
            throw new \InvalidArgumentException('Invalid header.');
        }
        $this->headers[$name] = $value;
        return $this;
    }

    public function send(): void
    {
        if (!headers_sent()) {
            http_response_code($this->status);
            foreach ($this->headers as $name => $value) {
                header($name . ': ' . $value);
            }
        }
        if ($this->file === null) {
            echo $this->body;
            return;
        }
        ignore_user_abort(true); // the finally below must run even when the browser disconnects
        try {
            readfile($this->file);
        } finally {
            @unlink($this->file);
            $this->file = null;
        }
    }

    /** Deletes the temp file of a response that will not be sent (error paths, tests). */
    public function discard(): void
    {
        if ($this->file !== null) {
            @unlink($this->file);
            $this->file = null;
        }
    }
}
