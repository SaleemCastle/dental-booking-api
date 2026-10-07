<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    private function browserHeaders(): array
    {
        return [
            'Accept' => 'application/json',
            'Origin' => config('app.frontend_url'),
            'Referer' => config('app.frontend_url').'/',
        ];
    }

    public function test_csrf_cookie_endpoint_sets_browser_session_cookies(): void
    {
        $response = $this
            ->withHeaders($this->browserHeaders())
            ->get('/sanctum/csrf-cookie');

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('statusCode', 200)
            ->assertJsonPath('message', 'CSRF cookie initialized.')
            ->assertJsonPath('code', null)
            ->assertJsonPath('errors', null)
            ->assertJsonPath('meta', null)
            ->assertCookie('XSRF-TOKEN')
            ->assertCookie(config('session.cookie'));
    }

    public function test_users_can_authenticate_using_the_login_screen(): void
    {
        $user = User::factory()->create();

        $this->withHeaders($this->browserHeaders())->get('/sanctum/csrf-cookie');

        $response = $this
            ->withHeaders($this->browserHeaders())
            ->postJson('/login', [
                'email' => $user->email,
                'password' => 'password',
            ]);

        $this->assertAuthenticated();
        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('statusCode', 200)
            ->assertJsonPath('message', 'Login successful.')
            ->assertJsonPath('data.user.email', $user->email);
    }

    public function test_authenticated_browser_session_can_fetch_api_user(): void
    {
        $user = User::factory()->create();

        $this->withHeaders($this->browserHeaders())->get('/sanctum/csrf-cookie');
        $this->withHeaders($this->browserHeaders())->postJson('/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $this
            ->withHeaders($this->browserHeaders())
            ->getJson('/api/user')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('statusCode', 200)
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonPath('data.email', $user->email);
    }

    public function test_new_users_can_register_and_fetch_api_user_from_browser_session(): void
    {
        $this->withHeaders($this->browserHeaders())->get('/sanctum/csrf-cookie');

        $response = $this
            ->withHeaders($this->browserHeaders())
            ->postJson('/register', [
                'name' => 'Test User',
                'email' => 'test@example.com',
                'password' => 'password',
                'password_confirmation' => 'password',
            ]);

        $response
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('statusCode', 201)
            ->assertJsonPath('message', 'Registration successful.')
            ->assertJsonPath('data.user.email', 'test@example.com');
        $this->assertAuthenticated();

        $apiUserResponse = $this
            ->withHeaders($this->browserHeaders())
            ->getJson('/api/user');

        $apiUserResponse
            ->assertOk()
            ->assertJsonPath('data.email', 'test@example.com');
    }

    public function test_users_can_logout_from_browser_session(): void
    {
        $user = User::factory()->create();

        $this->withHeaders($this->browserHeaders())->get('/sanctum/csrf-cookie');
        $this->withHeaders($this->browserHeaders())->postJson('/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $this
            ->withHeaders($this->browserHeaders())
            ->postJson('/logout')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('statusCode', 200)
            ->assertJsonPath('message', 'Logout successful.');

        $this->assertGuest();

        $this
            ->withHeaders($this->browserHeaders())
            ->getJson('/api/user')
            ->assertUnauthorized();
    }

    public function test_users_can_not_authenticate_with_invalid_password(): void
    {
        $user = User::factory()->create();

        $this->withHeaders($this->browserHeaders())->get('/sanctum/csrf-cookie');

        $this
            ->withHeaders($this->browserHeaders())
            ->postJson('/login', [
                'email' => $user->email,
                'password' => 'wrong-password',
            ])
            ->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonPath('statusCode', 422)
            ->assertJsonPath('code', 'VALIDATION_ERROR')
            ->assertJsonValidationErrors(['email']);

        $this->assertGuest();
    }

    public function test_api_user_returns_json_unauthenticated_error(): void
    {
        $this
            ->withHeaders($this->browserHeaders())
            ->getJson('/api/user')
            ->assertUnauthorized()
            ->assertJsonPath('success', false)
            ->assertJsonPath('statusCode', 401)
            ->assertJsonPath('message', 'Unauthenticated.')
            ->assertJsonPath('code', 'UNAUTHENTICATED');
    }

    public function test_guest_only_auth_endpoints_return_json_when_already_authenticated(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);

        $this
            ->withHeaders($this->browserHeaders())
            ->postJson('/login', [
                'email' => $user->email,
                'password' => 'password',
            ])
            ->assertStatus(409)
            ->assertJsonPath('success', false)
            ->assertJsonPath('statusCode', 409)
            ->assertJsonPath('message', 'Already authenticated.')
            ->assertJsonPath('code', 'ALREADY_AUTHENTICATED');
    }
}
