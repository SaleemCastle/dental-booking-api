<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    // use RefreshDatabase;

    // public function test_users_can_authenticate_using_the_login_screen(): void
    // {
    //     $this->actingAs(User::factory()->create());

    //     $response = $this->post('/login', [
    //         'email' => 'email',
    //         'password' => 'password',
    //     ]);

    //     $this->assertAuthenticated();
    //     $response->assertNoContent();
    // }

    // public function test_users_can_not_authenticate_with_invalid_password(): void
    // {
    //     $user = User::factory()->create();

    //     $this->post('/login', [
    //         'email' => $user->email,
    //         'password' => 'wrong-password',
    //     ]);

    //     $this->assertGuest();
    // }
    public function test_the_application_returns_a_successful_response(): void
    {
        $response = $this->get('/');

        $response->assertStatus(200);
    }
}
