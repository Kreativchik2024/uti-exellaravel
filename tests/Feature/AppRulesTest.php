<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\PgpRecord;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Правила приложения: безопасность, рабочее место, форма ПГП.
 */
class AppRulesTest extends TestCase
{
    use RefreshDatabase;

    private function adminWithEmployee(string $shortName = 'Тестов Т.Т.'): User
    {
        $user = User::factory()->admin()->create();
        Employee::create([
            'user_id' => $user->id, 'last_name' => 'Тестов', 'first_name' => 'Т', 'middle_name' => 'Т',
            'short_name' => $shortName, 'is_active' => true,
        ]);

        return $user;
    }

    /** Базовые поля записи ПГП (статус и прочее — в самих тестах). */
    private function pgpBase(string $worker): array
    {
        return [
            'record_date'  => now()->toDateString(),
            'post_number'  => 3,
            'worker1'      => $worker,
            'board_number' => 'B-1',
        ];
    }

    // ---------------- Безопасность ----------------

    public function test_media_serves_news_images_but_not_backups(): void
    {
        $root = storage_path('app/private');
        File::ensureDirectoryExists("$root/news/test");
        File::ensureDirectoryExists("$root/backup-test");
        File::put("$root/news/test/pic.txt", 'img');
        File::put("$root/backup-test/db.zip", 'secret');

        try {
            $user = User::factory()->admin()->create(); // один вход на устройство — только у исполнителей
            $this->actingAs($user)->get('/media/news/test/pic.txt')->assertOk();
            $this->actingAs($user)->get('/media/backup-test/db.zip')->assertNotFound();
            $this->actingAs($user)->get('/media/news/../backup-test/db.zip')->assertNotFound();
        } finally {
            File::deleteDirectory("$root/news/test");
            File::deleteDirectory("$root/backup-test");
        }
    }

    public function test_deactivated_user_is_logged_out_on_next_request(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->get('/profile')->assertOk();

        $user->update(['is_active' => false]);

        $this->get('/profile')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_inactive_user_cannot_log_in(): void
    {
        $user = User::factory()->inactive()->create();

        $this->post('/login', ['email' => $user->email, 'password' => 'password']);

        $this->assertGuest();
    }

    public function test_workstation_does_not_redirect_to_external_site(): void
    {
        $user = User::factory()->admin()->create(); // один вход на устройство — только у исполнителей

        $this->actingAs($user)
            ->post('/workstation/select', ['intended' => 'https://evil.example.com/phish', 'type' => 'post', 'post' => 5])
            ->assertRedirect(route('dashboard'));

        $this->actingAs($user)
            ->post('/workstation/select', ['intended' => '//evil.example.com', 'type' => 'post'])
            ->assertRedirect(route('dashboard'));

        $this->actingAs($user)
            ->post('/workstation/select', ['intended' => '/stats', 'type' => 'post', 'post' => 7])
            ->assertRedirect('/stats')
            ->assertSessionHas('workstation_post', '7');
    }

    public function test_pin_check_is_rate_limited(): void
    {
        $user = User::factory()->admin()->create(); // один вход на устройство — только у исполнителей

        for ($i = 0; $i < 10; $i++) {
            $this->actingAs($user)->postJson('/records-pin-check', ['short_name' => 'X', 'pin' => '1234'])
                ->assertStatus(404); // сотрудник не найден — но попытка засчитана
        }

        $this->actingAs($user)->postJson('/records-pin-check', ['short_name' => 'X', 'pin' => '1234'])
            ->assertStatus(429);
    }

    public function test_executor_second_device_logs_out_first(): void
    {
        $user = User::factory()->create(); // исполнитель
        $user->forceFill(['session_id' => 'session-on-another-device'])->save();

        $this->actingAs($user)->get('/profile')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_account_deletion_requires_password(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->delete('/profile', ['password' => ''])
            ->assertSessionHasErrorsIn('userDeletion', 'password');

        $this->assertNotNull($user->fresh());
    }

    public function test_csrf_token_endpoint_returns_fresh_token(): void
    {
        $this->getJson('/csrf-token')
            ->assertOk()
            ->assertJsonStructure(['token'])
            ->assertHeader('Cache-Control');
    }

    // ---------------- Форма ПГП ----------------

    public function test_pgp_not_delivered_requires_inspection_fields(): void
    {
        $user = $this->adminWithEmployee();

        $this->actingAs($user)->withSession(['workstation_workers' => []])
            ->post('/records/pgp', $this->pgpBase('Тестов Т.Т.') + ['ip_address' => '45', 'status' => 'Не сдан'])
            ->assertSessionHasErrors([
                'fuselage_number', 'frame_serial', 'cross3_number',
                'bracket_serial', 'defect_reason', 'fault_code',
            ])
            ->assertSessionDoesntHaveErrors(['actions_taken', 'ip_address']);

        $this->assertSame(0, PgpRecord::count());
    }

    public function test_pgp_delivered_saves_without_inspection_fields_and_builds_ip(): void
    {
        $user = $this->adminWithEmployee();

        $this->actingAs($user)->withSession(['workstation_workers' => []])
            ->post('/records/pgp', $this->pgpBase('Тестов Т.Т.') + [
                'ip_address' => '007', 'status' => 'Сдан', 'work_end' => '09:15',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('records.index', 'pgp'));

        $record = PgpRecord::firstOrFail();
        $this->assertSame('192.168.1.7', $record->ip_address);
        $this->assertSame('09:15', \Carbon\Carbon::parse($record->work_end)->format('H:i'));
    }

    public function test_pgp_not_delivered_with_all_fields_and_custom_fault_code(): void
    {
        $user = $this->adminWithEmployee();

        $this->actingAs($user)->withSession(['workstation_workers' => []])
            ->post('/records/pgp', $this->pgpBase('Тестов Т.Т.') + [
                'ip_address' => '254', 'status' => 'Не сдан',
                'fuselage_number' => 'F1', 'frame_serial' => 'M1', 'cross3_number' => 'C1',
                'bracket_serial' => 'K1', 'defect_reason' => 'не включается', 'fault_code' => 'СВОЙ-1',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('СВОЙ-1', PgpRecord::firstOrFail()->fault_code);
    }

    public function test_pgp_board_and_ip_optional_only_when_not_delivered(): void
    {
        $user = $this->adminWithEmployee();
        $base = array_diff_key($this->pgpBase('Тестов Т.Т.'), ['board_number' => 1]);

        // «Сдан» — номер борта и IP обязательны
        $this->actingAs($user)->withSession(['workstation_workers' => []])
            ->post('/records/pgp', $base + ['board_number' => '', 'ip_address' => '', 'status' => 'Сдан'])
            ->assertSessionHasErrors(['board_number', 'ip_address']);

        // «Не сдан» — можно без них (наклейки уходят на другой борт)
        $this->actingAs($user)->withSession(['workstation_workers' => []])
            ->post('/records/pgp', $base + [
                'board_number' => '', 'ip_address' => '', 'manet_number' => '', 'status' => 'Не сдан',
                'fuselage_number' => 'F1', 'frame_serial' => 'M1', 'cross3_number' => 'C1',
                'bracket_serial' => 'K1', 'defect_reason' => 'не включается', 'fault_code' => 'СВОЙ-1',
            ])
            ->assertSessionHasNoErrors();
        $record = PgpRecord::firstOrFail();
        $this->assertNull($record->board_number);
        $this->assertNull($record->ip_address);

        // Если IP всё же вписан — проверяется как обычно
        $this->actingAs($user)->withSession(['workstation_workers' => []])
            ->post('/records/pgp', $base + ['ip_address' => '300', 'status' => 'Не сдан'])
            ->assertSessionHasErrors('ip_address');

        // Форма: звёздочка и required переключаются по статусу
        $html = $this->actingAs($user)->get('/records/pgp/create')->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/data-required-when-field="status" data-required-when-value="Не сдан" data-required-unless="1"/', $html);
    }

    #[DataProvider('badIpProvider')]
    public function test_pgp_rejects_invalid_ip_suffix(string $suffix): void
    {
        $user = $this->adminWithEmployee();

        $this->actingAs($user)->withSession(['workstation_workers' => []])
            ->post('/records/pgp', $this->pgpBase('Тестов Т.Т.') + ['ip_address' => $suffix, 'status' => 'Сдан'])
            ->assertSessionHasErrors('ip_address');
    }

    public static function badIpProvider(): array
    {
        return ['ноль' => ['0'], '255' => ['255'], '300' => ['300'], 'буквы' => ['abc'], 'пусто' => ['']];
    }

    public function test_pgp_rejects_unknown_status(): void
    {
        $user = $this->adminWithEmployee();

        $this->actingAs($user)->withSession(['workstation_workers' => []])
            ->post('/records/pgp', $this->pgpBase('Тестов Т.Т.') + ['ip_address' => '10', 'status' => 'В работе'])
            ->assertSessionHasErrors('status');
    }

    public function test_pgp_create_form_renders_with_mask_and_hidden_inspection_block(): void
    {
        $user = $this->adminWithEmployee();

        $html = $this->actingAs($user)->withSession(['workstation_workers' => []])
            ->get('/records/pgp/create')
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('192.168.1.', $html);
        $this->assertMatchesRegularExpression('/data-show-when-filled="status"\s+hidden/', $html);
        $this->assertStringContainsString('x-data="faultCode(', $html);
        $this->assertMatchesRegularExpression('/name="work_end"\s+value="\d{2}:\d{2}"/', $html);
    }
}
