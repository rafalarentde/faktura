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
    private const SERVICE_ERRORS = [
        'SERVICE_UNAVAILABLE', 'MS_UNAVAILABLE', 'TIMEOUT', 'SERVER_BUSY',
        'MS_MAX_CONCURRENT_REQ', 'GLOBAL_MAX_CONCURRENT_REQ', 'MS_MAX_CONCURRENT_REQ_TIME', 'GLOBAL_MAX_CONCURRENT_REQ_TIME',
    ];

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
            $data = json_decode((string) $res['body'], true, 16, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return ViesStatus::Unavailable;
        }
        if (!is_array($data) || !empty($data['errorWrappers'])) {
            return ViesStatus::Unavailable;
        }
        if (in_array($data['userError'] ?? null, self::SERVICE_ERRORS, true)) {
            return ViesStatus::Unavailable;
        }
        return match ($data['isValid'] ?? null) {
            true => ViesStatus::Valid,
            false => ViesStatus::Invalid,
            default => ViesStatus::Unavailable,
        };
    }

    /** @return array{status: int, body: string} */
    private static function curl(string $url): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new \RuntimeException('curl_init failed');
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => self::TIMEOUT,
            CURLOPT_TIMEOUT => self::TIMEOUT,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
            CURLOPT_USERAGENT => 'faktura-vies/1.0',
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        if (!is_string($body)) {
            throw new \RuntimeException('VIES request failed: ' . $error);
        }
        return ['status' => $status, 'body' => $body];
    }
}
