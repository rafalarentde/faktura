<?php
declare(strict_types=1);

namespace App\Http;

use App\Core\Request;

/**
 * The query string of a document list (invoices, offers): search text, one status filter out of a
 * fixed list and the page number, read the same way everywhere. Unknown statuses give '' (no
 * filter), the search text is trimmed and cut to Q_MAX characters, a bad page number is page 1.
 * The pager helpers compute the page count and the prev/next links (all other filters kept).
 */
final class ListQuery
{
    public const PER_PAGE = 25;
    public const Q_MAX = 100;

    private function __construct(public readonly string $q, public readonly string $status, public readonly int $page)
    {
    }

    /**
     * @param list<string> $statuses the status values the list accepts
     * @param string $pageParam query parameter of the page number (a second list on the same page uses its own)
     */
    public static function fromRequest(Request $request, array $statuses, string $pageParam = 'page'): self
    {
        $status = $request->queryParam('status');
        $page = $request->queryParam($pageParam);
        return new self(
            mb_substr(trim($request->queryParam('q')), 0, self::Q_MAX),
            in_array($status, $statuses, true) ? $status : '',
            ctype_digit($page) ? max(1, (int) $page) : 1
        );
    }

    public static function pages(int $total): int
    {
        return max(1, (int) ceil($total / self::PER_PAGE));
    }

    /** The requested page, clamped to the last one. */
    public function clampedPage(int $total): int
    {
        return min($this->page, self::pages($total));
    }

    /**
     * Pager view data of the clamped page.
     *
     * @param array<string, string> $filters query parameters to keep (empty values are dropped)
     * @return array{page: int, pages: int, prev: ?string, next: ?string, filtered: bool}
     */
    public function pager(string $path, array $filters, int $total, string $pageParam = 'page'): array
    {
        $page = $this->clampedPage($total);
        $pages = self::pages($total);
        $base = array_filter($filters, fn (string $v): bool => $v !== '');
        $link = fn (int $p): string => $path . '?' . http_build_query($base + [$pageParam => $p]);
        return [
            'page' => $page, 'pages' => $pages,
            'prev' => $page > 1 ? $link($page - 1) : null,
            'next' => $page < $pages ? $link($page + 1) : null,
            'filtered' => $base !== [],
        ];
    }
}
