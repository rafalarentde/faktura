<?php
declare(strict_types=1);

namespace App\Vies;

use App\Domain\VatId;

/**
 * European Commission VIES REST API. Any transport problem, non-200 answer, unreadable body or
 * an error reported by the service (service/member state unavailable, busy, timeout) is
 * Unavailable: an error is never reported as Invalid. TLS verification is always on.
 */
final class HttpViesClient implements ViesClient
{
    public const BASE_URL = 'https://ec.europa.eu/taxation_customs/vies/rest-api/ms/';
    private const TIMEOUT = 5;
    /** Responses larger than this are not parsed (a VIES answer is a few hundred bytes). */
    public const MAX_BODY = 65536;
    /** userError values that accompany a definite answer; any other value is a service problem. */
    private const DEFINITE_ERRORS = ['VALID', 'INVALID'];

    /** @var \Closure(string): array{status: int, body: string} */
    private \Closure $transport;

    /** @param (callable(string): array{status: int, body: string})|null $transport injectable for tests; may throw */
    public function __construct(?callable $transport = null)
    {
        $this->transport = $transport !== null ? \Closure::fromCallable($transport) : self::curl(...);
    }

    public function check(string $country, string $vatId): ViesStatus
    {
        $prefix = VatId::prefix($country);
        $number = strtoupper(preg_replace('/[\s.\-]+/u', '', $vatId) ?? '');
        if (str_starts_with($number, $prefix)) {
            $number = substr($number, strlen($prefix));
        }
        $url = self::BASE_URL . rawurlencode($prefix) . '/vat/' . rawurlencode($number);
        try {
            $res = ($this->transport)($url);
            if (($res['status'] ?? 0) !== 200) {
                return ViesStatus::Unavailable;
            }
            $body = (string) $res['body'];
            if ($body === '' || strlen($body) > self::MAX_BODY) {
                return ViesStatus::Unavailable;
            }
            $data = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return ViesStatus::Unavailable;
        }
        // Allow-list: Valid only for a strict boolean true; Invalid only for a strict boolean false
        // with no userError or a definite one. Everything else is Unavailable.
        if (!is_array($data) || array_is_list($data) || !empty($data['errorWrappers'])) {
            return ViesStatus::Unavailable;
        }
        $isValid = $data['isValid'] ?? null;
        if ($isValid === true) {
            return ViesStatus::Valid;
        }
        $userError = $data['userError'] ?? null;
        if ($isValid === false && ($userError === null || in_array($userError, self::DEFINITE_ERRORS, true))) {
            return ViesStatus::Invalid;
        }
        return ViesStatus::Unavailable;
    }

    /** @return array{status: int, body: string} */
    private static function curl(string $url): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new \RuntimeException('curl_init failed');
        }
        curl_setopt_array($ch, [
            CURLOPT_CONNECTTIMEOUT => self::TIMEOUT,
            CURLOPT_TIMEOUT => self::TIMEOUT,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
            CURLOPT_USERAGENT => 'faktura-vies/1.0',
            // Abort (write callback returns a short count) once the body exceeds the cap.
            CURLOPT_WRITEFUNCTION => static function ($ch, string $chunk) use (&$received): int {
                $received .= $chunk;
                return strlen($received) > self::MAX_BODY + 1 ? 0 : strlen($chunk);
            },
        ]);
        $received = '';
        $ok = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        if ($ok === false) {
            throw new \RuntimeException('VIES request failed: ' . $error);
        }
        return ['status' => $status, 'body' => $received];
    }
}
