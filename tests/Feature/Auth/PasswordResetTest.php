<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Восстановление пароля по почте выключено (почты в изолированной сети нет):
 * пароль сбрасывает администратор. Включается PASSWORD_RESET_EMAIL=true.
 */
class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_password_reset_by_email_is_disabled(): void
    {
        $user = User::factory()->create();

        $this->get('/forgot-password')->assertNotFound();
        $this->post('/forgot-password', ['email' => $user->email])->assertNotFound();
        $this->get('/reset-password/any-token')->assertNotFound();

        $this->get('/login')->assertOk()->assertSee('к администратору');
    }
}
