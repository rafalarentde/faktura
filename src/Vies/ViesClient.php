<?php
declare(strict_types=1);

namespace App\Vies;

interface ViesClient
{
    /**
     * @param string $country ISO country code or VAT prefix (GR and EL both mean Greece)
     * @param string $vatId   the number, with or without the country prefix
     * @return ViesStatus Unavailable whenever the answer is not a definite yes/no; never throws
     */
    public function check(string $country, string $vatId): ViesStatus;
}
