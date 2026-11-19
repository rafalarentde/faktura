<?php
declare(strict_types=1);

namespace App\Vies;

/** Result of a VIES check; the backing strings are what customers.vies_status stores (NULL = never checked). */
enum ViesStatus: string
{
    case Valid = 'valid';
    case Invalid = 'invalid';
    case Unavailable = 'unavailable';
}
