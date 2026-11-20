<?php
declare(strict_types=1);

namespace Tests\Http;

use App\Core\Request;
use App\Http\ListQuery;
use PHPUnit\Framework\TestCase;

final class ListQueryTest extends TestCase
{
    private function q(array $query): ListQuery
    {
        return ListQuery::fromRequest(new Request('GET', '/x', query: $query), ['open', 'paid']);
    }

    public function test_parameters_are_read_defensively(): void
    {
        $a = $this->q(['q' => '  Müller  ', 'status' => 'open', 'page' => '3']);
        $this->assertSame(['Müller', 'open', 3], [$a->q, $a->status, $a->page]);
        $b = $this->q(['q' => str_repeat('ä', 300), 'status' => 'bogus', 'page' => '-2']);
        $this->assertSame([ListQuery::Q_MAX, '', 1], [mb_strlen($b->q), $b->status, $b->page]);
        $c = $this->q(['q' => ['x'], 'status' => ['open'], 'page' => ['1']]);
        $this->assertSame(['', '', 1], [$c->q, $c->status, $c->page]);
        $this->assertSame(1, $this->q(['page' => '0'])->page);
        $this->assertSame(1, $this->q(['page' => '1e3'])->page);
    }

    public function test_pager_clamps_and_keeps_filters(): void
    {
        $q = $this->q(['page' => '99']);
        $this->assertSame(1, ListQuery::pages(0));
        $this->assertSame(2, ListQuery::pages(26));
        $this->assertSame(2, $q->clampedPage(26));
        $p = $q->pager('/offers', ['q' => 'a b', 'status' => '', 'type' => 'x'], 26);
        $this->assertSame([2, 2, '/offers?q=a+b&type=x&page=1', null, true], [$p['page'], $p['pages'], $p['prev'], $p['next'], $p['filtered']]);
        $p = $this->q([])->pager('/offers', ['q' => ''], 60);
        $this->assertSame([1, 3, null, '/offers?page=2', false], [$p['page'], $p['pages'], $p['prev'], $p['next'], $p['filtered']]);
    }
}
