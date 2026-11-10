<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\PgpRecord;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SiteHelpers;
use Tests\TestCase;

/** Журнал действий: кто, когда и что изменил. */
class AuditLogTest extends TestCase
{
    use RefreshDatabase, SiteHelpers;

    private function pgp(array $over = []): PgpRecord
    {
        return PgpRecord::create($over + [
            'record_date' => now()->toDateString(), 'post_number' => 1, 'worker1' => 'Иванов И.',
            'board_number' => 'RA-100', 'ip_address' => '192.168.1.5', 'status' => 'Сдан',
        ]);
    }

    public function test_record_create_update_delete_are_logged_with_before_after(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        $rec = $this->pgp();
        $rec->update(['status' => 'Не сдан', 'defect_reason' => 'нет связи']);
        $rec->delete();

        $logs = AuditLog::where('subject_type', 'pgp')->where('subject_id', $rec->id)->orderBy('id')->get();
        $this->assertSame(['created', 'updated', 'deleted'], $logs->pluck('action')->all());
        $this->assertStringContainsString('RA-100', $logs[0]->summary);
        $this->assertSame($admin->id, $logs[1]->user_id);
        $this->assertSame(['Сдан', 'Не сдан'], $logs[1]->changes['status']);
        $this->assertSame([null, 'нет связи'], $logs[1]->changes['defect_reason']);
        $this->assertSame('RA-100', $logs[2]->changes['board_number'][0], 'содержимое удалённой записи сохранено');
    }

    public function test_admin_actions_and_logins_are_logged_without_secrets(): void
    {
        $admin = User::factory()->admin()->create(['password' => 'Secret123!']);
        $user = User::factory()->create();

        $this->post('/login', ['email' => $admin->email, 'password' => 'wrong']);
        $this->assertTrue(AuditLog::where('action', 'login_failed')->exists());

        $this->asUser($admin)->patch("/users/{$user->id}/role", ['role' => 'master']);
        $this->asUser($admin)->patch("/users/{$user->id}/password", ['password' => 'NewPass123!', 'password_confirmation' => 'NewPass123!']);

        $role = AuditLog::where('action', 'role')->firstOrFail();
        $this->assertSame(['executor', 'master'], $role->changes['роль']);
        $this->assertTrue(AuditLog::where('action', 'password')->exists());
        $this->assertStringNotContainsString('NewPass123!', AuditLog::all()->toJson(), 'пароли в журнал не попадают');
    }

    public function test_only_admin_sees_journal_and_master_sees_record_history(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);
        $rec = $this->pgp();
        $rec->update(['board_number' => 'RA-200']);

        $this->asUser($admin)->get('/audit')->assertOk()->assertSee('RA-200');
        $this->asUser($admin)->get('/audit?q=RA-200')->assertOk()->assertSee('RA-100');   // было → стало
        $this->asUser($this->worker())->get('/audit')->assertForbidden();

        $master = User::factory()->master()->create();
        $master->sections()->attach(\App\Models\Section::first()->id);
        $this->asUser($master)->get("/records/pgp/{$rec->id}")->assertOk()->assertSee('История изменений');
    }

    public function test_old_entries_are_pruned(): void
    {
        AuditLog::write('auth', 'login', 'старое');
        AuditLog::query()->update(['created_at' => now()->subDays(AuditLog::RETENTION_DAYS + 1)]);
        AuditLog::write('auth', 'login', 'свежее');

        $this->artisan('audit:prune')->assertSuccessful();
        $this->assertSame(['свежее'], AuditLog::pluck('summary')->all());
    }
}
