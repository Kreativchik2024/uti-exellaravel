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

    public function test_buttons_run_tasks_and_show_result(): void
    {
        $admin = User::factory()->admin()->create();

        $this->asUser($admin)->get('/system')->assertSee('Обновить кеш сайта')->assertSee('Очистить старше 7 дней');

        $this->asUser($admin)->post('/system/run/chat-prune')->assertRedirect()->assertSessionHas('ok');
        $task = $this->asUser($admin)->getJson('/system/status')->assertOk()->json();
        $this->assertSame('done', $task['status']);
        $this->assertStringContainsString('Удалено сообщений', $task['output']);
        $this->assertTrue(\App\Models\AuditLog::where('area', 'system')->where('action', 'run')->exists());

        $this->asUser($admin)->post('/system/run/rm-rf')->assertNotFound();   // только разрешённые задачи
        $this->asUser($this->worker())->post('/system/run/chat-prune')->assertForbidden();
    }

    public function test_second_task_is_not_started_while_first_is_running(): void
    {
        $admin = User::factory()->admin()->create();
        cache()->forever(\App\Console\Commands\SystemTask::CACHE_KEY, ['task' => 'backup-full', 'label' => 'Полная копия', 'status' => 'running', 'started_at' => now()->toIso8601String()]);

        $this->asUser($admin)->post('/system/run/chat-prune')->assertSessionHasErrors('task');
    }

    public function test_backup_download_requires_password_and_logs_can_be_cleared(): void
    {
        $admin = User::factory()->admin()->create();

        $this->asUser($admin)->get('/system/backup/download')->assertRedirect(route('password.confirm'));

        // Временная папка вместо настоящих логов
        $tmp = sys_get_temp_dir() . '/uti-logs-' . uniqid();
        mkdir($tmp . '/logs', 0777, true);
        $real = storage_path();
        $this->app->useStoragePath($tmp);
        file_put_contents($tmp . '/logs/laravel.log', 'old error');
        try {
            $this->asUser($admin)->post('/system/logs/clear')->assertSessionHas('ok');
            $this->assertSame('', file_get_contents($tmp . '/logs/laravel.log'));
        } finally {
            $this->app->useStoragePath($real);
            @unlink($tmp . '/logs/laravel.log');
        }
    }

    public function test_errors_page_groups_errors_from_log(): void
    {
        $admin = User::factory()->admin()->create();
        $now = now()->format('Y-m-d H:i:s');
        $old = now()->subDays(3)->format('Y-m-d H:i:s');
        $log = <<<LOG
[{$old}] production.ERROR: Старая ошибка {"exception":"[object] (Exception(code: 0): x at /app/Old.php:1)"}
[{$now}] production.ERROR: Route [system.logs.download] not defined. {"userId":{$admin->id},"exception":"[object] (Symfony\\\\Component\\\\Routing\\\\Exception\\\\RouteNotFoundException(code: 0): Route not defined. at /var/www/uti/vendor/laravel/framework/src/Illuminate/Routing/UrlGenerator.php:542)
[stacktrace]
#0 /var/www/uti/app/Http/Controllers/SystemController.php(120): route()
#1 {main}
"}
[{$now}] production.INFO: просто информация
[{$now}] production.ERROR: Route [system.logs.download] not defined. {"userId":{$admin->id}}
LOG;

        $tmp = sys_get_temp_dir() . '/uti-err-' . uniqid();
        mkdir($tmp . '/logs', 0777, true);
        file_put_contents($tmp . '/logs/laravel.log', $log);
        $real = storage_path();
        $this->app->useStoragePath($tmp);
        try {
            $groups = app(\App\Services\LogReader::class)->grouped(24);
            $this->assertCount(1, $groups, 'одинаковые сгруппированы, INFO и старые — не попадают');
            $this->assertSame(2, $groups[0]['count']);
            $this->assertStringContainsString('UrlGenerator.php:542', (string) collect(app(\App\Services\LogReader::class)->errors(24))->pluck('location')->filter()->first());

            $this->asUser($admin)->get('/system')->assertSee('Посмотреть ошибки');
            $this->asUser($admin)->get('/system/errors')->assertOk()
                ->assertSee('Route [system.logs.download] not defined.')->assertSee('2×')->assertSee('SystemController.php(120)')
                ->assertDontSee('Старая ошибка');
            $this->asUser($admin)->get('/system/errors?hours=168')->assertSee('Старая ошибка');
            $this->asUser($this->worker())->get('/system/errors')->assertForbidden();
        } finally {
            $this->app->useStoragePath($real);
            @unlink($tmp . '/logs/laravel.log');
        }
    }

    public function test_backup_verify_reports_missing_backups(): void
    {
        config(['backup.backup.name' => 'нет-такой-папки-' . uniqid()]);

        $this->artisan('backup:verify')->assertFailed();
        $this->assertFalse(cache(\App\Console\Commands\VerifyBackup::CACHE_KEY)['ok']);
    }
}
