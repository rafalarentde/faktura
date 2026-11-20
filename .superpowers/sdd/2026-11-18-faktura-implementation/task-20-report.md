# Task 20 report: dunning logic

## Implementation
- `App\Domain\DunningLevel` (enum, `translationKey()` -> `dunning.level_<n>`; keys already existed in de/en/pl, reused, no lang changes).
- `App\Domain\DunningSettings` (readonly-style value object; `defaults()`, `fromCompanyRow()` strict, accepts numeric strings from PDO).
- `App\Domain\Dunning` (static, pure), `App\Domain\FlatFeeNotAllowed extends \DomainException`.
- `App\Money\Rounding::divHalfUp` extracted from Totals (Totals now calls it; behaviour unchanged).

## Dunning domain API for Task 21
- `Dunning::suggest(int $daysOverdue, ?DunningLevel $lastSent, DunningSettings $s): ?DunningLevel`
- `Dunning::daysOverdue(string $dueDate, \DateTimeImmutable $today): int` (signed; Berlin date of $today; `\InvalidArgumentException` on invalid Y-m-d)
- `Dunning::annualRateBp(DunningSettings $s, bool $isCompany): ?int`
- `Dunning::interest(Money $outstanding, int $days, ?int $baseRateBp, int $surchargeBp): ?Money` (null if base null; zero if days<=0, outstanding<=0 or rate<=0; `\OverflowException`)
- `Dunning::flatFee(bool $isCompany, DunningSettings $s): Money` (consumer: `FlatFeeNotAllowed`)
- `Dunning::fee(DunningSettings $s): Money`; `Dunning::feesAllowedFor(string $currency): bool` (EUR only)
- `Dunning::claimTotal(Money $outstanding, ?Money $interest, ?Money $flatFee, ?Money $fee): Money` (`\InvalidArgumentException` on currency mismatch)
- `DunningSettings::fromCompanyRow(array): self` / `defaults()`; `new DunningSettings(list<int> $thresholds, ?int $baseRateBp, int $b2b, int $b2c, int $flat, int $fee)`; throws `\InvalidArgumentException`.
- Label keys: `dunning.level_0..3`.

## Oracle comparison
- Dunning::interest vs Python Fraction, 5,000 cases (outstanding up to 2^63-1, days up to 1e15, rates up to 1e9): 0 mismatches; 3,481 computed, 1,519 threw OverflowException (84 of those have a true result that fits in int64 but an intermediate product overflows: allowed by ruling "exact or throws"). Scripts: scratchpad `dgen.py`, `dchk.php`.
- Totals after Rounding extraction: gen.py/chk.php 9,121 cases, 0 mismatches.

## Decisions beyond rulings
- Settings also reject negative surcharges/fees and thresholds count != 4.
- No bcmath/gmp in this PHP, so the in-repo test uses 64-bit-safe reference (1000 seeded random triples via local Mt19937) and large-value test only asserts exact known value / OverflowException / non-negative.
- daysOverdue truncates via UTC-midnight difference (no DST effects).

## Tests
- Tests\Domain DunningTest + DunningSettingsTest: 78 tests, 10,403 assertions. Full suite: OK (1412 tests, 27406 assertions), no warnings.

## TDD evidence
- Caveat: implementation was written before the tests; RED was demonstrated afterwards by moving src/Domain/Dunning.php aside: `vendor/bin/phpunit tests/Domain/DunningTest.php` -> "Tests: 59, Assertions: 36, Errors: 46, Failures: 11" (Class "App\Domain\Dunning" not found). GREEN with it restored: `OK (78 tests ...)` (1 skipped earlier for bcmath, helper then removed; final run no skips).

## Files
src/Domain/{Dunning,DunningLevel,DunningSettings,FlatFeeNotAllowed}.php, src/Money/Rounding.php, src/Domain/Totals.php (uses Rounding), tests/Domain/{DunningTest,DunningSettingsTest}.php

## Concerns
- Spurious OverflowException for extreme inputs (see above); irrelevant for real amounts.
- Strict-TDD order not followed (see caveat).
