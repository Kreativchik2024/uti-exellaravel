<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SiteHelpers;
use Tests\TestCase;

/** Страница «Состояние сервера» и проверка резервной копии. */
class SystemPageTest extends TestCase
{
    use RefreshDatabase, SiteHelpers;

    public function test_admin_sees_system_status_others_dont(): void
    {
        $this->asUser(User::factory()->admin()->create())->get('/system')
            ->assertOk()->assertSee('Резервные копии')->assertSee('Планировщик')->assertSee('Режим отладки');
        $this->asUser($this->worker())->get('/system')->assertForbidden();
    }

    public function test_backup_verify_reports_missing_backups(): void
    {
        config(['backup.backup.name' => 'нет-такой-папки-' . uniqid()]);

        $this->artisan('backup:verify')->assertFailed();
        $this->assertFalse(cache(\App\Console\Commands\VerifyBackup::CACHE_KEY)['ok']);
    }
}
