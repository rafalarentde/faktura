<?php
declare(strict_types=1);

namespace App\Domain;

/**
 * Thrown by repositories/services when input is invalid; nothing has been written.
 * `errors` maps a field name to a translation key (e.g. ['name' => 'customer.err_name']);
 * controllers translate the keys with App::t() and re-render the form with status 422.
 */
final class ValidationException extends \RuntimeException
{
    /** @param array<string, string> $errors field => translation key */
    public function __construct(public readonly array $errors)
    {
        parent::__construct('Validation failed: ' . implode(', ', array_keys($errors)));
    }
}
