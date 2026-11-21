<?php
declare(strict_types=1);

namespace App\Http;

/** Chip CSS class of a document status; the one mapping shared by the invoice and offer lists and the dashboard. */
final class StatusChips
{
    private const INVOICE = ['draft' => 'chip-draft', 'open' => 'chip-sent', 'partial' => 'chip-soon', 'overdue' => 'chip-late',
        'paid' => 'chip-ok', 'credited' => 'chip-draft', 'issued' => 'chip-sent'];
    private const OFFER = ['draft' => 'chip-draft', 'open' => 'chip-sent', 'sent' => 'chip-sent', 'accepted' => 'chip-ok',
        'rejected' => 'chip-late', 'expired' => 'chip-soon'];

    /** Invoices and credit notes (draft|open|partial|overdue|paid|credited|issued). */
    public static function invoice(string $status): string
    {
        return self::INVOICE[$status] ?? 'chip-draft';
    }

    /** Offers (draft|open|sent|accepted|rejected|expired). */
    public static function offer(string $status): string
    {
        return self::OFFER[$status] ?? 'chip-draft';
    }
}
