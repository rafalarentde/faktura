<?php
declare(strict_types=1);

namespace App\Tenant;

/** The user has no access to the requested company (no membership, or no such company). */
final class AccessDenied extends \RuntimeException
{
}
