<?php
declare(strict_types=1);

namespace Tests\Domain;

use App\Domain\Countries;
use PHPUnit\Framework\TestCase;

final class CountriesTest extends TestCase
{
    public function test_eu_membership(): void
    {
        foreach (['DE', 'PL', 'FR', 'GR'] as $c) {
            $this->assertTrue(Countries::isEu($c), $c);
        }
        foreach (['US', 'CH', 'GB', 'NO', 'XI', 'EL', ''] as $c) {
            $this->assertFalse(Countries::isEu($c), $c);
        }
        $this->assertCount(27, Countries::EU);
    }

    public function test_all_contains_only_valid_codes(): void
    {
        foreach (Countries::all() as $c) {
            $this->assertTrue(Countries::isValid($c));
        }
    }
}
