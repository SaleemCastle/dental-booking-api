<?php

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use RuntimeException;
use Tests\TestCase;

class ApiResponseContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_success_response_uses_standard_envelope_and_request_id(): void
    {
        $this->getJson('/api/health', ['X-Request-Id' => 'contract-test-id'])
            ->assertOk()
            ->assertHeader('X-Request-Id', 'contract-test-id')
            ->assertJsonPath('success', true)
            ->assertJsonPath('statusCode', 200)
            ->assertJsonPath('message', 'Service is healthy.')
            ->assertJsonPath('code', null)
            ->assertJsonPath('data.status', 'ok')
            ->assertJsonPath('errors', null)
            ->assertJsonPath('meta', null)
            ->assertJsonPath('requestId', 'contract-test-id');
    }

    public function test_validation_errors_use_standard_envelope(): void
    {
        $this->postJson('/api/auth/token', [])
            ->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonPath('statusCode', 422)
            ->assertJsonPath('message', 'The given data was invalid.')
            ->assertJsonPath('code', 'VALIDATION_ERROR')
            ->assertJsonValidationErrors(['email', 'password'])
            ->assertJsonPath('data', null)
            ->assertJsonPath('meta', null);
    }

    public function test_not_found_errors_use_standard_envelope(): void
    {
        $this->getJson('/api/missing-route')
            ->assertNotFound()
            ->assertJsonPath('success', false)
            ->assertJsonPath('statusCode', 404)
            ->assertJsonPath('message', 'Resource not found.')
            ->assertJsonPath('code', 'NOT_FOUND')
            ->assertJsonPath('data', null);
    }

    public function test_method_not_allowed_errors_use_standard_envelope(): void
    {
        $this->postJson('/api/health')
            ->assertStatus(405)
            ->assertJsonPath('success', false)
            ->assertJsonPath('statusCode', 405)
            ->assertJsonPath('message', 'Method not allowed.')
            ->assertJsonPath('code', 'METHOD_NOT_ALLOWED');
    }

    public function test_server_errors_are_frontend_safe_and_correlated_by_request_id(): void
    {
        Route::get('/api/test-server-error', function () {
            throw new RuntimeException('Raw internal database password leaked.');
        });

        $this->getJson('/api/test-server-error', ['X-Request-Id' => 'server-error-id'])
            ->assertStatus(500)
            ->assertHeader('X-Request-Id', 'server-error-id')
            ->assertJsonPath('success', false)
            ->assertJsonPath('statusCode', 500)
            ->assertJsonPath('message', 'An unexpected error occurred.')
            ->assertJsonPath('code', 'SERVER_ERROR')
            ->assertJsonPath('requestId', 'server-error-id')
            ->assertDontSee('Raw internal database password leaked.');
    }
}
