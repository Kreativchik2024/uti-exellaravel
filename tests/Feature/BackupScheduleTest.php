<?php

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Правило бэкапов: база — каждый час, полный — в 19:50, хранится неделя. */
class BackupScheduleTest extends TestCase
{
    use RefreshDatabase;

    public function test_schedule_hourly_db_and_full_at_1950(): void
    {
        $events = collect(app(Schedule::class)->events())->mapWithKeys(fn ($e) => [$e->command => $e->expression]);
        $find = fn (string $needle) => $events->first(fn ($expr, $cmd) => str_contains((string) $cmd, $needle));

        $this->assertSame('5 8-19 * * *', $find('backup:run --only-db'), 'копия базы каждый час в :05 (08:05–19:05)');
        $this->assertSame('50 19 * * *', $find("backup:run --disable-notifications"), 'полная копия в 19:50');
        $this->assertNotNull($find('backup:clean'), 'очистка старых копий');
    }

    /** Сервер работает 08:00–20:00: ни одна задача не должна приходиться на ночь. */
    public function test_all_tasks_run_while_server_is_on(): void
    {
        foreach (app(Schedule::class)->events() as $event) {
            if ($event->expression === '* * * * *') continue;   // отметка «планировщик жив»

            foreach (range(0, 23) as $hour) {
                $minute = explode(' ', $event->expression)[0];
                $this->travelTo(now()->startOfDay()->setTime($hour, ctype_digit($minute) ? (int) $minute : 0));
                if ($event->isDue(app()) && $event->filtersPass(app())) {
                    $this->assertTrue($hour >= 8 && $hour < 20, "«{$event->command}» запланирована на {$hour}:xx — сервер в это время выключен");
                }
            }
        }

        // Ежечасная копия базы: 08:05 … 19:05
        $hourly = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains((string) $e->command, '--only-db'));
        $runs = collect(range(0, 23))->filter(function ($h) use ($hourly) {
            $this->travelTo(now()->startOfDay()->setTime($h, 5));
            return $hourly->isDue(app()) && $hourly->filtersPass(app());
        })->values()->all();
        $this->assertSame(range(8, 19), $runs);
    }

    public function test_backups_older_than_week_are_deleted(): void
    {
        Storage::fake('local');
        config(['backup.backup.destination.disks' => ['local'], 'backup.monitor_backups.0.disks' => ['local']]);
        $dir = config('backup.backup.name');
        $disk = Storage::disk('local');

        foreach ([1, 3, 6, 8, 10, 20] as $daysAgo) {
            $file = "$dir/uti-" . now()->subDays($daysAgo)->format('Y-m-d-H-i-s') . '.zip';
            $disk->put($file, 'zip');
            touch($disk->path($file), now()->subDays($daysAgo)->getTimestamp());
        }

        $this->artisan('backup:clean', ['--disable-notifications' => true])->assertSuccessful();

        $left = collect($disk->files($dir))->count();
        $this->assertSame(3, $left, 'остались копии за 1, 3 и 6 дней; 8, 10 и 20 дней — удалены');
    }
}
