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
        $this->get('/login')->assertOk()->assertSee('Cookie и персональные данные')->assertSee(route('consent.accept'), false);
        $this->get('/rules')->assertOk()->assertSee('152-ФЗ')->assertSee('id="cookies"', false);
    }

    public function test_accepting_sets_cookie_and_hides_popup(): void
    {
        $this->post('/consent', [])->assertSessionHasErrors('accept');

        $res = $this->from('/login')->post('/consent', ['accept' => '1'])->assertRedirect('/login');
        $res->assertCookie(config('legal.cookie'), config('legal.version'));

        $this->withCookie(config('legal.cookie'), config('legal.version'))
            ->get('/login')->assertOk()->assertDontSee('Cookie и персональные данные');
    }

    public function test_logged_in_user_consent_is_recorded_and_new_edition_asks_again(): void
    {
        $user = User::factory()->admin()->create();

        $this->actingAs($user)->withCookie(config('legal.cookie'), config('legal.version'))
            ->get('/profile')->assertSee('Cookie и персональные данные');   // согласие браузера не переносится на учётку

        $this->actingAs($user)->post('/consent', ['accept' => '1']);
        $user->refresh();
        $this->assertSame(config('legal.version'), $user->consent_version);
        $this->assertNotNull($user->consent_at);

        $this->actingAs($user)->withCookie(config('legal.cookie'), config('legal.version'))
            ->get('/profile')->assertDontSee('Cookie и персональные данные');

        config(['legal.version' => '2099-01-01']);   // новая редакция правил
        $this->actingAs($user)->withCookie(config('legal.cookie'), '2026-11-10')
            ->get('/profile')->assertSee('Cookie и персональные данные');
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
            ->assertHeader('Referrer-Policy', 'same-origin');
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
