<?php
declare(strict_types=1);

namespace App\Domain;

/** The visible status of an offer row; a pure function shared by DocumentService::offerStatus() and the dashboard. */
final class OfferStatus
{
    /**
     * draft | open | sent | accepted | rejected | expired. 'expired' is computed: a stored open or sent
     * status whose valid_until lies before the Berlin date of $today (valid until today is still
     * valid). Accepted and rejected offers never expire.
     *
     * @param array<string, mixed> $document a documents row of an offer
     */
    public static function of(array $document, \DateTimeImmutable $today): string
    {
        if (($document['status'] ?? null) !== 'issued') {
            return 'draft';
        }
        $stored = in_array($document['offer_status'] ?? null, DocumentService::OFFER_STATUSES, true) ? (string) $document['offer_status'] : 'open';
        $validUntil = $document['valid_until'] ?? null;
        if (in_array($stored, ['open', 'sent'], true) && $validUntil !== null
            && $validUntil < $today->setTimezone(new \DateTimeZone('Europe/Berlin'))->format('Y-m-d')) {
            return 'expired';
        }
        return $stored;
    }
}
