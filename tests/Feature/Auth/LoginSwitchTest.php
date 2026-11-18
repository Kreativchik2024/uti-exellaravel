<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** Вход под другим логином, когда в браузере уже открыта чья-то сессия. */
class LoginSwitchTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_form_with_other_credentials_switches_user(): void
    {
        $old = User::factory()->create(['is_active' => true]);
        $new = User::factory()->create(['is_active' => true, 'password' => Hash::make('secret-pass-1')]);

        $this->actingAs($old)->post('/login', ['email' => $new->email, 'password' => 'secret-pass-1'])
            ->assertRedirect();

        $this->assertAuthenticatedAs($new);
    }
}
