<?php

namespace Tests\Feature;

use App\Exports\SafeValueBinder;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Tests\TestCase;

/** Защита: согласие с правилами, заголовки, подбор ПИН, формулы в Excel. */
class SecurityTest extends TestCase
{
    use RefreshDatabase;

    // ---------------------------------------------------------------- согласие

    public function test_first_visit_shows_consent_and_rules_page_is_public(): void
    {
        $this->withoutConsentCookie();

        $this->get('/login')->assertOk()->assertSee('Cookie и персональные данные')->assertSee(route('consent.accept'), false)
            ->assertDontSee('name="password"', false);   // вместо страницы — только окно согласия
        $this->get('/rules')->assertOk()->assertSee('152-ФЗ')->assertSee('id="cookies"', false);
    }

    public function test_site_cannot_be_used_without_consent_even_bypassing_popup(): void
    {
        $this->withoutConsentCookie();
        $user = User::factory()->admin()->create(['consent_version' => null, 'consent_at' => null]);

        // Страницы — только окно согласия
        $this->actingAs($user)->get('/')->assertOk()->assertSee('Cookie и персональные данные')->assertDontSee('ui-chat');
        // Запросы данных и действия — 403, даже если окно скрыли в браузере
        $this->actingAs($user)->getJson('/chat/messages?peer=0')->assertForbidden()->assertJsonPath('consent_required', true);
        $this->actingAs($user)->postJson('/chat/messages', ['peer' => 0, 'body' => 'обход'])->assertForbidden();
        $this->actingAs($user)->post('/login', ['email' => 'x', 'password' => 'y'])->assertForbidden();
        $this->assertSame(0, \App\Models\ChatMessage::count());

        // Выйти можно и без согласия
        $this->actingAs($user)->post('/logout')->assertRedirect();
    }

    public function test_accepting_sets_cookie_and_opens_site(): void
    {
        $this->withoutConsentCookie();
        $this->post('/consent', [])->assertSessionHasErrors('accept');

        $res = $this->from('/login')->post('/consent', ['accept' => '1'])->assertRedirect('/login');
        $res->assertCookie(config('legal.cookie'), config('legal.version'));

        $this->withCookie(config('legal.cookie'), config('legal.version'))
            ->get('/login')->assertOk()->assertDontSee('Cookie и персональные данные')->assertSee('name="password"', false);
    }

    public function test_logged_in_user_consent_is_recorded_and_new_edition_asks_again(): void
    {
        $user = User::factory()->admin()->create(['consent_version' => null, 'consent_at' => null]);

        // Согласие, данное в браузере до входа, на учётку не переносится
        $this->actingAs($user)->get('/profile')->assertSee('Cookie и персональные данные')->assertSee('Не согласен — выйти');

        $this->actingAs($user)->post('/consent', ['accept' => '1']);
        $user->refresh();
        $this->assertSame(config('legal.version'), $user->consent_version);
        $this->assertNotNull($user->consent_at);

        $this->actingAs($user)->get('/profile')->assertOk()->assertDontSee('Cookie и персональные данные');

        config(['legal.version' => '2099-01-01']);   // новая редакция правил
        $this->actingAs($user)->get('/profile')->assertSee('Cookie и персональные данные');
        $this->actingAs($user)->getJson('/chat/summary')->assertForbidden();
    }

    public function test_registration_requires_consent(): void
    {
        $form = ['name' => 'Новиков А.А.', 'email' => 'new@uti.local', 'password' => 'Secret123!', 'password_confirmation' => 'Secret123!'];

        $this->post('/register', $form)->assertSessionHasErrors('accept_rules');
        $this->post('/register', $form + ['accept_rules' => '1'])->assertSessionHasNoErrors();

        $this->assertSame(config('legal.version'), User::where('email', 'new@uti.local')->value('consent_version'));
    }

    // ---------------------------------------------------------------- заголовки

    public function test_security_headers_are_sent(): void
    {
        $this->get('/login')
            ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Referrer-Policy', 'same-origin')
            ->assertHeaderMissing('Strict-Transport-Security');   // по HTTP HSTS не шлём

        $this->get('https://localhost/login')->assertHeader('Strict-Transport-Security', 'max-age=2592000');
    }

    // ---------------------------------------------------------------- прочее

    public function test_cannot_mark_foreign_employee_busy_without_pin(): void
    {
        $owner = User::factory()->create(['role' => 'executor', 'is_active' => true]);
        $emp = Employee::create(['last_name' => 'Орлов', 'first_name' => 'Иван', 'short_name' => 'Орлов И.', 'is_active' => true, 'user_id' => $owner->id]);
        $stranger = User::factory()->create(['role' => 'executor', 'is_active' => true]);

        $this->actingAs($stranger)->postJson('/worker-sessions/claim', ['short_name' => 'Орлов И.', 'sheet' => 'pgp'])->assertForbidden();
        $stranger->forceFill(['session_id' => null])->saveQuietly();   // «один вход на устройство» у исполнителей
        $this->actingAs($stranger)->withSession(['verified_workers' => [$emp->id => now()->addHour()->timestamp]])
            ->postJson('/worker-sessions/claim', ['short_name' => 'Орлов И.', 'sheet' => 'pgp'])->assertJsonPath('ok', true);
    }

    public function test_unpublished_news_hidden_from_regular_users(): void
    {
        $admin = User::factory()->admin()->create();
        $news = \App\Models\News::create(['title' => 'Черновик', 'body' => 'текст', 'is_published' => false, 'author_id' => $admin->id]);
        $worker = User::factory()->create(['role' => 'executor', 'is_active' => true]);

        $this->actingAs($worker)->withSession(['workstation_workers' => []])->get("/news/{$news->id}")->assertNotFound();
        $this->actingAs($admin)->get("/news/{$news->id}")->assertOk();
    }

    public function test_role_and_pin_cannot_be_mass_assigned(): void
    {
        $u = new User(['name' => 'X', 'email' => 'x@x', 'password' => 'secret', 'role' => 'admin', 'is_active' => true, 'pin_hash' => 'h']);
        $this->assertNull($u->role);
        $this->assertNull($u->is_active);
        $this->assertNull($u->pin_hash);
    }

    public function test_last_admin_cannot_delete_own_account(): void
    {
        $admin = User::factory()->admin()->create(['password' => 'Secret123!']);

        $this->actingAs($admin)->delete('/profile', ['password' => 'Secret123!'])->assertSessionHasErrorsIn('userDeletion', 'password');
        $this->assertNotNull($admin->fresh());
    }

    // ---------------------------------------------------------------- подбор ПИН сотрудника

    public function test_employee_pin_locks_after_5_failures_from_any_account(): void
    {
        $owner = User::factory()->admin()->create();
        $owner->setPin('4321');
        $emp = Employee::create(['last_name' => 'Сидоров', 'first_name' => 'Пётр', 'short_name' => 'Сидоров П.', 'is_active' => true, 'user_id' => $owner->id]);

        // 5 ошибок с разных учёток
        foreach (range(1, 5) as $i) {
            $this->actingAs(User::factory()->admin()->create())
                ->postJson('/records-pin-check', ['short_name' => $emp->short_name, 'pin' => '000' . $i])->assertStatus(422);
        }

        // Теперь даже верный ПИН не принимается
        $this->actingAs(User::factory()->admin()->create())
            ->postJson('/records-pin-check', ['short_name' => $emp->short_name, 'pin' => '4321'])->assertStatus(429);
    }

    // ---------------------------------------------------------------- автовыход

    public function test_idle_user_is_logged_out_but_background_polling_does_not_keep_session(): void
    {
        config(['auth.idle_logout_minutes' => 15]);
        $user = User::factory()->admin()->create();

        $this->actingAs($user)->get('/profile')->assertOk();

        // Фоновый опрос чата каждые 10 минут — не активность
        $this->travel(10)->minutes();
        $this->getJson('/chat/summary')->assertOk();
        $this->travel(6)->minutes();
        $this->get('/profile')->assertRedirect('/login');
        $this->assertGuest();
        $this->assertTrue(\App\Models\AuditLog::where('action', 'logout')->exists());
    }

    public function test_session_lasts_until_logout_and_idle_limit_is_30_minutes(): void
    {
        $this->assertSame(30, config('auth.idle_logout_minutes'));
        $this->assertGreaterThanOrEqual(720, config('session.lifetime'), 'сессия — не меньше рабочего дня');
        $this->assertFalse(config('session.expire_on_close'));

        $user = User::factory()->admin()->create();
        $this->actingAs($user)->get('/profile')->assertOk();
        $this->travel(29)->minutes();
        $this->get('/profile')->assertOk();   // 29 минут без действий — ещё в системе
        $this->travel(31)->minutes();
        $this->get('/profile')->assertRedirect('/login');   // больше 30 — выход
    }

    public function test_ping_keeps_session_alive_while_user_works(): void
    {
        config(['auth.idle_logout_minutes' => 15]);
        $user = User::factory()->admin()->create();

        $this->actingAs($user)->get('/profile')->assertOk();
        foreach (range(1, 3) as $i) {
            $this->travel(10)->minutes();
            $this->getJson('/session/ping')->assertOk();   // браузер: человек печатает
        }
        $this->get('/profile')->assertOk();
    }

    // ---------------------------------------------------------------- Excel

    public function test_excel_export_never_writes_formulas_from_user_data(): void
    {
        $sheet = (new Spreadsheet())->getActiveSheet();
        $binder = new SafeValueBinder();

        $cell = $sheet->getCell('A1');
        $binder->bindValue($cell, '=HYPERLINK("http://evil","клик")');
        $this->assertSame(DataType::TYPE_STRING, $cell->getDataType());

        $cell = $sheet->getCell('A2');
        $binder->bindValue($cell, 42);
        $this->assertSame(DataType::TYPE_NUMERIC, $cell->getDataType(), 'числа остаются числами');
    }
}
