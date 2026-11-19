<?php
declare(strict_types=1);

namespace Tests\Support;

use App\Vies\ViesClient;
use App\Vies\ViesStatus;

/** Test double: never touches the network. */
final class FakeViesClient implements ViesClient
{
    /** @var list<array{string, string}> */
    public array $calls = [];

    public function __construct(public ViesStatus $result = ViesStatus::Valid)
    {
    }

    public function check(string $country, string $vatId): ViesStatus
    {
        $this->calls[] = [$country, $vatId];
        return $this->result;
    }
}
