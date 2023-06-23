<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
// use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    // use RefreshDatabase;

    // public function test_reset_password_link_can_be_requested(): void
    // {
    //     Notification::fake();

    //     $user = User::factory()->create();

    //     $this->post('/forgot-password', ['email' => $user->email]);

    //     Notification::assertSentTo($user, ResetPassword::class);
    // }

    // public function test_password_can_be_reset_with_valid_token(): void
    // {
    //     Notification::fake();

    //     $user = User::factory()->create();

    //     $this->post('/forgot-password', ['email' => $user->email]);

    //     Notification::assertSentTo($user, ResetPassword::class, function (object $notification) use ($user) {
    //         $response = $this->post('/reset-password', [
    //             'token' => $notification->token,
    //             'email' => $user->email,
    //             'password' => 'password',
    //             'password_confirmation' => 'password',
    //         ]);

    //         $response->assertSessionHasNoErrors();

    //         return true;
    //     });
    // }
    public function test_the_application_returns_a_successful_response(): void
    {
        $response = $this->get('/');

        $response->assertStatus(200);
    }
}
