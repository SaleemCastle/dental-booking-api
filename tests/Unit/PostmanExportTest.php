<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use PostmanExport;
use Symfony\Component\Yaml\Yaml;

require_once __DIR__.'/../../tools/postman.php';

class PostmanExportTest extends TestCase
{
    private array $document;

    private array $export;

    protected function setUp(): void
    {
        parent::setUp();
        $this->document = Yaml::parseFile(__DIR__.'/../../docs/openapi.yaml');
        $this->export = (new PostmanExport($this->document))->build();
    }

    public function test_committed_exports_match_the_contract(): void
    {
        foreach (['collection' => 'dental-booking.postman_collection.json', 'environment' => 'local.postman_environment.json'] as $key => $filename) {
            $this->assertSame(PostmanExport::json($this->export[$key]), file_get_contents(__DIR__.'/../../docs/postman/'.$filename));
        }
        $this->assertSame($this->export, (new PostmanExport($this->document))->build());
    }

    public function test_every_documented_operation_is_exported_once(): void
    {
        $expected = [];
        foreach ($this->document['paths'] as $path => $definition) {
            foreach (array_keys($definition) as $method) {
                if (in_array($method, ['get', 'post', 'put', 'patch', 'delete'], true)) {
                    $expected[] = strtoupper($method).' '.preg_replace('/\{([^}]+)\}/', '{{$1_id}}', $path);
                }
            }
        }
        $actual = [];
        foreach ($this->requests() as $request) {
            $actual[] = $request['method'].' /'.implode('/', $request['url']['path']);
            $this->assertStringStartsWith('{{base_url}}/', $request['url']['raw']);
            $this->assertContains(['key' => 'Accept', 'value' => 'application/json'], $request['header']);
        }
        sort($expected);
        sort($actual);
        $this->assertSame($expected, $actual);
        $this->assertCount(count($actual), array_unique($actual));
        $this->assertSame('bearer', $this->export['collection']['auth']['type']);
    }

    public function test_audit_requests_are_read_only_with_optional_filters(): void
    {
        $folder = array_values(array_filter($this->export['collection']['item'], fn ($folder) => $folder['name'] === 'Audit Logs'))[0];
        $this->assertCount(3, $folder['item']);
        foreach ($folder['item'] as $item) {
            $this->assertSame('GET', $item['request']['method']);
        }
        $query = array_column($folder['item'][0]['request']['url']['query'], null, 'key');
        $expected = ['actor_id', 'actor_is_null', 'action', 'resource_type', 'resource_id', 'request_id', 'from', 'to', 'page', 'per_page'];
        $this->assertEqualsCanonicalizing($expected, array_keys($query));
        foreach ($query as $key => $parameter) {
            $this->assertSame(! in_array($key, ['page', 'per_page'], true), $parameter['disabled']);
        }
        $this->assertSame('{{audit_request_id}}', $query['request_id']['value']);
        $this->assertSame('{{base_url}}/api/audit-logs/{{audit_log_id}}', $folder['item'][2]['request']['url']['raw']);
    }

    public function test_billing_requests_follow_the_snapshot_and_append_only_contract(): void
    {
        $invoice = $this->bodyFor('POST', '/api/invoices');
        $this->assertSame('USD', $invoice['currency']);
        $this->assertSame('100.00', $invoice['items'][0]['unit_price']);
        $this->assertArrayNotHasKey('amount_due', $invoice);
        $this->assertArrayNotHasKey('status', $invoice);
        $this->assertSame(['status' => 'issued'], $this->bodyFor('PATCH', '/api/invoices/{{invoice_id}}'));
        $payment = $this->bodyFor('POST', '/api/payments');
        $this->assertArrayHasKey('idempotency_key', $payment);
        $this->assertArrayHasKey('currency', $payment);
        $this->assertArrayNotHasKey('status', $payment);
        foreach ($this->requests() as $request) {
            $path = '/'.implode('/', $request['url']['path']);
            if (str_starts_with($path, '/api/payments')) {
                $this->assertContains($request['method'], ['GET', 'POST']);
            }
            if (str_starts_with($path, '/api/invoices')) {
                $this->assertNotSame('DELETE', $request['method']);
            }
        }
    }

    public function test_availability_sample_has_working_hours_a_lunch_break_and_no_fixed_date_override(): void
    {
        $body = $this->bodyFor('PUT', '/api/dentists/{{dentist_id}}/availability');
        $this->assertCount(5, $body['weekly_hours']);
        $this->assertSame(['day_of_week' => 1, 'start_time' => '09:00', 'end_time' => '17:00'], $body['weekly_hours'][0]);
        $this->assertSame(['day_of_week' => 1, 'start_time' => '12:00', 'end_time' => '13:00'], $body['breaks'][0]);
        $this->assertSame([], $body['exceptions']);
    }

    public function test_request_bodies_are_json_objects_with_declared_variables(): void
    {
        $variables = array_fill_keys(array_column($this->export['environment']['values'], 'key'), 'test-value');
        $variables['password_json'] = json_encode('Password with "quotes" and \\slashes');
        $variables['new_user_password_json'] = json_encode('New password with "quotes"');
        foreach ($this->requests() as $request) {
            $raw = $request['body']['raw'] ?? null;
            if ($raw === null) {
                continue;
            }
            $resolved = preg_replace_callback('/\{\{([a-zA-Z0-9_]+)\}\}/', function ($matches) use ($variables) {
                $this->assertArrayHasKey($matches[1], $variables);

                return $variables[$matches[1]];
            }, $raw);
            $body = json_decode($resolved, flags: JSON_THROW_ON_ERROR);
            $this->assertInstanceOf(\stdClass::class, $body);
        }
        $appointment = $this->bodyFor('POST', '/api/appointments');
        $this->assertSame('{{patient_id}}', $appointment['patient_id']);
        $this->assertSame('{{dentist_id}}', $appointment['dentist_id']);
        $this->assertSame('{{appointment_start}}', $appointment['appointment_date_time']);
        $this->assertArrayHasKey('appointment_type', $appointment);
        $this->assertSame('{{assignee_id}}', $this->bodyFor('POST', '/api/tasks')['assignee_id']);
        $this->assertSame('{{sample_email}}', $this->bodyFor('POST', '/api/dentists')['email']);
        $this->assertSame('{{invoice_number}}', $this->bodyFor('POST', '/api/invoices')['invoice_number']);
        $this->assertSame('Patient requested cancellation.', $this->bodyFor('POST', '/api/appointments/{{appointment_id}}/cancel')['cancellation_reason']);
        $this->assertSame(['patient_id', 'dentist_id', 'note_text'], array_keys($this->bodyFor('POST', '/api/clinical-notes')));
    }

    public function test_credentials_ids_and_write_flags_are_safe_by_default(): void
    {
        $values = array_column($this->export['environment']['values'], null, 'key');
        foreach (['email', 'password', 'new_user_password', 'access_token', 'patient_id', 'user_id', 'audit_log_id'] as $key) {
            $this->assertSame('', $values[$key]['value']);
        }
        foreach (['allow_writes', 'allow_auth_cleanup', 'use_session_auth'] as $key) {
            $this->assertSame('false', $values[$key]['value']);
        }
        foreach (['password', 'new_user_password', 'access_token'] as $key) {
            $this->assertSame('secret', $values[$key]['type']);
        }
        $token = $this->requestFor('POST', '/api/auth/token');
        $this->assertSame('noauth', $token['auth']['type']);
        $this->assertArrayNotHasKey('auth', $this->requestFor('GET', '/api/audit-logs'));
    }

    private function requests(): array
    {
        $requests = [];
        foreach ($this->export['collection']['item'] as $folder) {
            foreach ($folder['item'] as $item) {
                $requests[] = $item['request'];
            }
        }

        return $requests;
    }

    private function requestFor(string $method, string $path): array
    {
        foreach ($this->requests() as $request) {
            if ($request['method'] === $method && '/'.implode('/', $request['url']['path']) === $path) {
                return $request;
            }
        }
        $this->fail('Request not found: '.$method.' '.$path);
    }

    private function bodyFor(string $method, string $path): array
    {
        return json_decode($this->requestFor($method, $path)['body']['raw'], true, flags: JSON_THROW_ON_ERROR);
    }
}
