<?php
declare(strict_types=1);

namespace App\Repo;

use App\Domain\DocumentNotFound;

/**
 * Send log of the context company (table email_log; BaseRepo scoped helpers only). One row per
 * send attempt of an issued document. Rows hold the recipient, the subject, the outcome and a
 * short reason code on failure; never the body, never server text, never credentials.
 */
final class EmailLogRepo extends BaseRepo
{
    private const TABLE = 'email_log';
    public const STATUSES = ['sent', 'failed'];

    /**
     * @param string|null $error reason code (auth, connect, tls, recipient, other) for failed rows
     * @return int id of the new row
     * @throws DocumentNotFound the document is not one of the context company
     * @throws \InvalidArgumentException unknown status
     */
    public function record(int $documentId, string $to, string $subject, string $status, ?string $error, ?int $sentBy, int $at): int
    {
        if (!in_array($status, self::STATUSES, true)) {
            throw new \InvalidArgumentException('Unknown email log status.');
        }
        if ($this->scopedOne('documents', $documentId) === null) {
            throw new DocumentNotFound();
        }
        return $this->scopedInsert(self::TABLE, [
            'document_id' => $documentId,
            'to_email' => mb_substr($to, 0, 254),
            'subject' => mb_substr($subject, 0, 255),
            'status' => $status,
            'error' => $status === 'failed' ? mb_substr((string) $error, 0, 40) : null,
            'sent_by' => $sentBy,
            'created_at' => gmdate('Y-m-d H:i:s', $at),
        ]);
    }

    /** @return list<array<string, mixed>> the document's log rows, newest first */
    public function forDocument(int $documentId): array
    {
        return array_map(fn (array $r): array => $this->normalize($r), $this->scopedAll(self::TABLE, 'document_id = ?', [$documentId], 'id DESC'));
    }

    /** True when at least one send of the document succeeded ("send again" wording). */
    public function hasSent(int $documentId): bool
    {
        return $this->scopedCount(self::TABLE, "document_id = ? AND status = 'sent'", [$documentId]) > 0;
    }

    /** @param array<string, mixed> $r @return array<string, mixed> */
    private function normalize(array $r): array
    {
        $r['id'] = (int) $r['id'];
        $r['company_id'] = (int) $r['company_id'];
        $r['document_id'] = (int) $r['document_id'];
        $r['sent_by'] = $r['sent_by'] === null ? null : (int) $r['sent_by'];
        return $r;
    }
}
