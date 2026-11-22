<?php
declare(strict_types=1);

namespace App\Domain;

/** Per-company dunning configuration (company settings columns dunning_*). */
final class DunningSettings
{
    /** Bounds (also used by the settings form): days overdue, annual rates in basis points, amounts in minor units. */
    public const MAX_DAYS = 365;
    public const BASE_RATE_MIN_BP = -1000;
    public const BASE_RATE_MAX_BP = 3000;
    public const SURCHARGE_MAX_BP = 2000;
    public const MAX_AMOUNT_MINOR = 100_000; // 1000 EUR for the flat fee and the per-dunning fee

    /** @var list<int> d0..d3, strictly ascending, days overdue */
    public readonly array $thresholds;

    /**
     * @param list<int> $thresholds
     * @throws \InvalidArgumentException
     */
    public function __construct(
        array $thresholds,
        public readonly ?int $baseRateBp,
        public readonly int $b2bSurchargeBp,
        public readonly int $b2cSurchargeBp,
        public readonly int $flatMinor,
        public readonly int $feeMinor,
    ) {
        if (count($thresholds) !== 4) {
            throw new \InvalidArgumentException('Exactly four thresholds are required.');
        }
        $prev = 0;
        foreach (array_values($thresholds) as $t) {
            if (!is_int($t) || $t < 1 || $t > self::MAX_DAYS || $t <= $prev) {
                throw new \InvalidArgumentException('Thresholds must be integers 1..365, strictly ascending.');
            }
            $prev = $t;
        }
        if ($b2bSurchargeBp < 0 || $b2cSurchargeBp < 0 || $flatMinor < 0 || $feeMinor < 0) {
            throw new \InvalidArgumentException('Surcharges and fees must not be negative.');
        }
        if ($b2bSurchargeBp > self::SURCHARGE_MAX_BP || $b2cSurchargeBp > self::SURCHARGE_MAX_BP) {
            throw new \InvalidArgumentException('A surcharge must not exceed 20 percentage points.');
        }
        if ($baseRateBp !== null && ($baseRateBp < self::BASE_RATE_MIN_BP || $baseRateBp > self::BASE_RATE_MAX_BP)) {
            throw new \InvalidArgumentException('The base rate must lie between -10 % and 30 %.');
        }
        if ($flatMinor > self::MAX_AMOUNT_MINOR || $feeMinor > self::MAX_AMOUNT_MINOR) {
            throw new \InvalidArgumentException('Fees must not exceed 1000.00.');
        }
        $this->thresholds = array_values($thresholds);
    }

    public static function defaults(): self
    {
        return new self([7, 21, 35, 49], null, 900, 500, 4000, 0);
    }

    /**
     * @param array<string, mixed> $row company row (dunning_* columns)
     * @throws \InvalidArgumentException
     */
    public static function fromCompanyRow(array $row): self
    {
        $int = static function (string $key) use ($row): int {
            $v = $row[$key] ?? null;
            if (is_string($v) && preg_match('/\A-?[0-9]{1,18}\z/', $v) === 1) {
                $v = (int) $v; // PDO may hand back numeric strings
            }
            if (!is_int($v)) {
                throw new \InvalidArgumentException("Column {$key} must be an integer.");
            }
            return $v;
        };
        $base = $row['dunning_base_rate_bp'] ?? null;
        return new self(
            [$int('dunning_d0'), $int('dunning_d1'), $int('dunning_d2'), $int('dunning_d3')],
            $base === null ? null : $int('dunning_base_rate_bp'),
            $int('dunning_b2b_surcharge_bp'),
            $int('dunning_b2c_surcharge_bp'),
            $int('dunning_flat_minor'),
            $int('dunning_fee_minor'),
        );
    }
}
