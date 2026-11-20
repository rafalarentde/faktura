<?php
declare(strict_types=1);

namespace App\Domain;

enum DunningLevel: int
{
    case Reminder = 0;
    case First = 1;
    case Second = 2;
    case Final = 3;

    /** i18n key of the level label (exists in lang/de.php, en.php, pl.php). */
    public function translationKey(): string
    {
        return 'dunning.level_' . $this->value;
    }
}
