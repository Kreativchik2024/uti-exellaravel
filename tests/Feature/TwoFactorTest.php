<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Вход с одноразовым кодом из приложения на телефоне. */
class TwoFactorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['auth.two_factor_roles' => ['admin']]);
    }

    public function test_totp_matches_rfc6238_reference(): void
    {
        // RFC 6238, приложение B: секрет "12345678901234567890", время 59 → 94287082 (8 цифр) → 287082 (6 цифр)
        $secret = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';
        $this->assertSame('287082', Totp::code($secret, intdiv(59, 30)));
        $this->assertSame('081804', Totp::code($secret, intdiv(1111111109, 30)));
    }

    public function test_admin_must_set_up_two_factor_before_working(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get('/users')->assertRedirect('/two-factor');
        $this->actingAs($admin)->getJson('/chat/summary')->assertForbidden();

        $page = $this->actingAs($admin)->get('/two-factor')->assertOk()->assertSee('<svg', false);
        $secret = session('two_factor_setup_secret');

        $this->actingAs($admin)->post('/two-factor/confirm', ['code' => '000000'])->assertSessionHasErrors('code');
        $this->actingAs($admin)->post('/two-factor/confirm', ['code' => Totp::code($secret, intdiv(now()->timestamp, 30))])
            ->assertRedirect('/two-factor')->assertSessionHas('recovery_codes');

        $admin->refresh();
        $this->assertTrue($admin->hasTwoFactor());
        $this->assertNotSame($secret, $admin->getRawOriginal('two_factor_secret'), 'секрет в базе зашифрован');
        $this->actingAs($admin)->get('/users')->assertOk();
    }

    public function test_new_session_requires_code_and_code_cannot_be_reused(): void
    {
        $secret = Totp::generateSecret();
        $admin = User::factory()->admin()->create(['password' => 'Secret123!']);
        $admin->forceFill(['two_factor_secret' => $secret, 'two_factor_recovery_codes' => ['AAAA-BBBB'], 'two_factor_confirmed_at' => now()])->save();

        $this->post('/login', ['email' => $admin->email, 'password' => 'Secret123!']);
        $this->get('/users')->assertRedirect('/two-factor/challenge');

        $code = Totp::code($secret, intdiv(now()->timestamp, 30));
        $this->post('/two-factor/challenge', ['code' => '123456'])->assertSessionHasErrors('code');
        $this->post('/two-factor/challenge', ['code' => $code])->assertRedirect();
        $this->get('/users')->assertOk();

        // Тот же код второй раз (подсмотрели) — не принимается
        $this->assertFalse(Totp::verify($secret, $code, $admin->id));
    }

    public function test_recovery_code_works_once(): void
    {
        $admin = User::factory()->admin()->create();
        $admin->forceFill(['two_factor_secret' => Totp::generateSecret(), 'two_factor_recovery_codes' => ['AAAA-BBBB', 'CCCC-DDDD'], 'two_factor_confirmed_at' => now()])->save();

        $this->actingAs($admin)->post('/two-factor/challenge', ['code' => 'aaaa-bbbb'])->assertRedirect();
        $this->assertSame(['CCCC-DDDD'], $admin->fresh()->two_factor_recovery_codes);
    }

    public function test_other_admin_and_console_can_reset_lost_phone(): void
    {
        $lost = User::factory()->admin()->create(['email' => 'lost@uti.local']);
        $lost->forceFill(['two_factor_secret' => Totp::generateSecret(), 'two_factor_confirmed_at' => now()])->save();

        $this->artisan('user:2fa-reset', ['email' => 'lost@uti.local'])->assertSuccessful();
        $this->assertFalse($lost->fresh()->hasTwoFactor());
    }

    public function test_workers_are_not_forced(): void
    {
        $worker = User::factory()->create();
        $worker->forceFill(['session_id' => null])->saveQuietly();

        $this->actingAs($worker)->withSession(['workstation_workers' => []])->get('/profile')->assertOk();
    }
}
