<?php
declare(strict_types=1);

namespace Tests\Vies;

use App\Vies\HttpViesClient;
use App\Vies\ViesStatus;
use PHPUnit\Framework\TestCase;

final class HttpViesClientTest extends TestCase
{
    /** @param array{status:int, body:string}|\Throwable $response */
    private function client(array|\Throwable $response, ?string &$url = null): HttpViesClient
    {
        return new HttpViesClient(function (string $u) use ($response, &$url): array {
            $url = $u;
            if ($response instanceof \Throwable) {
                throw $response;
            }
            return $response;
        });
    }

    private function body(array $data): array
    {
        return ['status' => 200, 'body' => json_encode($data, JSON_THROW_ON_ERROR)];
    }

    public function test_valid(): void
    {
        $c = $this->client($this->body(['isValid' => true, 'userError' => 'VALID']), $url);
        $this->assertSame(ViesStatus::Valid, $c->check('PL', '1234567890'));
        $this->assertSame('https://ec.europa.eu/taxation_customs/vies/rest-api/ms/PL/vat/1234567890', $url);
    }

    public function test_invalid(): void
    {
        $c = $this->client($this->body(['isValid' => false, 'userError' => 'INVALID']));
        $this->assertSame(ViesStatus::Invalid, $c->check('PL', '1234567890'));
    }

    public function test_greece_uses_el_and_prefix_is_stripped(): void
    {
        $c = $this->client($this->body(['isValid' => true]), $url);
        $c->check('GR', 'EL123456789');
        $this->assertStringContainsString('/ms/EL/vat/123456789', (string) $url);
    }

    public function test_input_is_url_encoded(): void
    {
        $c = $this->client($this->body(['isValid' => true]), $url);
        $c->check('DE', '12/3?x');
        $this->assertStringContainsString('/vat/12%2F3%3FX', (string) $url);
    }

    public function test_server_error_is_unavailable(): void
    {
        $this->assertSame(ViesStatus::Unavailable, $this->client(['status' => 500, 'body' => ''])->check('DE', '1'));
        $this->assertSame(ViesStatus::Unavailable, $this->client(['status' => 404, 'body' => '{"isValid":false}'])->check('DE', '1'));
    }

    public function test_malformed_json_is_unavailable(): void
    {
        $this->assertSame(ViesStatus::Unavailable, $this->client(['status' => 200, 'body' => '<html>'])->check('DE', '1'));
        $this->assertSame(ViesStatus::Unavailable, $this->client(['status' => 200, 'body' => '{}'])->check('DE', '1'));
        $this->assertSame(ViesStatus::Unavailable, $this->client(['status' => 200, 'body' => '"x"'])->check('DE', '1'));
    }

    public function test_transport_exception_is_unavailable(): void
    {
        $this->assertSame(ViesStatus::Unavailable, $this->client(new \RuntimeException('timeout'))->check('DE', '1'));
    }

    public function test_service_reported_errors_are_unavailable_even_when_not_valid(): void
    {
        foreach (['SERVICE_UNAVAILABLE', 'MS_UNAVAILABLE', 'TIMEOUT', 'MS_MAX_CONCURRENT_REQ', 'GLOBAL_MAX_CONCURRENT_REQ', 'SERVER_BUSY'] as $err) {
            $c = $this->client($this->body(['isValid' => false, 'userError' => $err]));
            $this->assertSame(ViesStatus::Unavailable, $c->check('DE', '1'), $err);
        }
        $c = $this->client($this->body(['actionSucceed' => false, 'errorWrappers' => [['error' => 'MS_UNAVAILABLE']]]));
        $this->assertSame(ViesStatus::Unavailable, $c->check('DE', '1'));
    }
}
