<?php
declare(strict_types=1);

namespace App\Domain;

/**
 * Writes a JSON array to a file one element at a time (one element per line), so the export of
 * thousands of documents never holds the whole list in memory. Output is UTF-8 without
 * escaped unicode or slashes; invalid UTF-8 in a value is replaced, never fatal.
 */
final class JsonArrayFile
{
    /** @var resource */
    private $handle;
    private int $count = 0;

    public function __construct(private string $path)
    {
        $h = fopen($path, 'wb');
        if ($h === false) {
            throw new \RuntimeException('Cannot write export file.');
        }
        $this->handle = $h;
        fwrite($this->handle, "[\n");
    }

    /** @param array<array-key, mixed> $element */
    public function add(array $element): void
    {
        $json = json_encode($element, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
        fwrite($this->handle, ($this->count === 0 ? '' : ",\n") . $json);
        $this->count++;
    }

    public function count(): int
    {
        return $this->count;
    }

    /** Closes the array and the file; returns the path. */
    public function close(): string
    {
        fwrite($this->handle, "\n]\n");
        fclose($this->handle);
        return $this->path;
    }
}
