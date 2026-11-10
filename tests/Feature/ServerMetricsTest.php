<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\ServerMetrics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SiteHelpers;
use Tests\TestCase;

/** Температура, процессор и память сервера — на подставных /proc и /sys как у Orange Pi. */
class ServerMetricsTest extends TestCase
{
    use RefreshDatabase, SiteHelpers;

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/uti-fakeroot-' . uniqid();
        $put = function (string $path, string $content) {
            @mkdir(dirname($this->root . $path), 0777, true);
            file_put_contents($this->root . $path, $content);
        };
        // Orange Pi 5 (RK3588): несколько датчиков, 8 ядер
        $put('/sys/class/thermal/thermal_zone0/type', "soc-thermal\n");
        $put('/sys/class/thermal/thermal_zone0/temp', "52300\n");
        $put('/sys/class/thermal/thermal_zone1/type', "bigcore0-thermal\n");
        $put('/sys/class/thermal/thermal_zone1/temp', "56800\n");
        $cores = '';
        foreach (range(0, 7) as $i) $cores .= "cpu{$i} 1000 0 500 8000 100 0 10 0 0 0\n";
        $put('/proc/stat', "cpu  8000 0 4000 64000 800 0 80 0 0 0\n{$cores}intr 12345\n");
        $put('/proc/loadavg', "0.52 0.48 0.40 1/320 12345\n");
        $put('/proc/meminfo', "MemTotal:        8000000 kB\nMemFree:         1000000 kB\nMemAvailable:    6000000 kB\n");
        $put('/proc/uptime', "93784.12 700000.00\n");
        $put('/sys/devices/system/cpu/cpu0/cpufreq/scaling_cur_freq', "1800000\n");
    }

    public function test_reads_orange_pi_sensors(): void
    {
        $m = (new ServerMetrics($this->root))->all();

        $this->assertSame(56.8, $m['temp']['max'], 'самая горячая точка');
        $this->assertCount(2, $m['temp']['sensors']);
        $this->assertSame(8, $m['cpu']['count']);
        $this->assertSame([0.52, 0.48, 0.4], $m['cpu']['load']);
        $this->assertSame(1800, $m['cpu']['mhz']);
        $this->assertSame(25.0, (float) $m['memory']['percent']);
        $this->assertSame(93784, $m['uptime']);
    }

    public function test_missing_sensors_do_not_break_page(): void
    {
        $m = (new ServerMetrics(sys_get_temp_dir() . '/нет-такой-папки'))->all();
        $this->assertNull($m['temp']);
        $this->assertNull($m['cpu']);
        $this->assertNull($m['memory']);

        $admin = User::factory()->admin()->create();
        $this->asUser($admin)->get('/system')->assertOk()->assertSee('Температура процессора');
        $this->asUser($admin)->getJson('/system/metrics')->assertOk()->assertJsonStructure(['temp', 'cpu', 'memory', 'uptime']);
        $this->asUser($this->worker())->getJson('/system/metrics')->assertForbidden();
    }

    public function test_overheating_is_shown_as_problem(): void
    {
        file_put_contents($this->root . '/sys/class/thermal/thermal_zone1/temp', "84000\n");
        $this->app->instance(ServerMetrics::class, new ServerMetrics($this->root));

        $page = $this->asUser(User::factory()->admin()->create())->get('/system')->assertOk();
        $checks = collect($page->viewData('checks')['Железо']);
        $this->assertSame('bad', $checks->firstWhere('title', 'Температура процессора')['status']);
    }
}
