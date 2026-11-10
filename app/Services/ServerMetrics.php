<?php

namespace App\Services;

/**
 * Показатели «железа» сервера (Linux / Orange Pi): температура, загрузка процессора,
 * память, время работы. Читаются из /sys и /proc; где их нет (Windows) — null.
 */
class ServerMetrics
{
    /** Пороги температуры процессора, °C (Orange Pi сбрасывает частоту около 85 °C). */
    public const TEMP_WARN = 70;
    public const TEMP_BAD = 80;

    /** $root — корень файловой системы (в тестах — папка с подставными /proc и /sys). */
    public function __construct(private string $root = '') {}

    private function path(string $p): string
    {
        return $this->root . $p;
    }

    public function all(): array
    {
        return [
            'temp'   => $this->temperature(),
            'cpu'    => $this->cpu(),
            'memory' => $this->memory(),
            'uptime' => $this->uptime(),
            'at'     => now()->toIso8601String(),
        ];
    }

    /** Самая горячая точка процессора, °C, и все датчики. */
    public function temperature(): ?array
    {
        $zones = @glob($this->path('/sys/class/thermal/thermal_zone*')) ?: [];
        $sensors = [];
        foreach ($zones as $z) {
            $raw = @file_get_contents("$z/temp");
            if ($raw === false || ! is_numeric(trim($raw))) continue;
            $t = (int) trim($raw);
            $t = $t > 1000 ? $t / 1000 : $t;   // обычно в тысячных градуса
            if ($t <= 0 || $t > 150) continue;
            $type = trim((string) @file_get_contents("$z/type")) ?: basename($z);
            $sensors[] = ['name' => $type, 'value' => round($t, 1)];
        }
        if (! $sensors) return null;

        return ['max' => max(array_column($sensors, 'value')), 'sensors' => $sensors];
    }

    /** Загрузка процессора, % (замер 0,3 с), ядра и средняя нагрузка за 1/5/15 минут. */
    public function cpu(): ?array
    {
        $a = $this->cpuTimes();
        if ($a === null) return null;
        usleep(300000);
        $b = $this->cpuTimes();

        $total = array_sum($b['total']) - array_sum($a['total']);
        $usage = $total > 0 ? round(100 * (1 - ($b['idle'] - $a['idle']) / $total), 1) : 0.0;

        $cores = [];
        foreach ($b['cores'] as $i => $core) {
            $t = array_sum($core) - array_sum($a['cores'][$i] ?? $core);
            $idle = ($core[3] + ($core[4] ?? 0)) - (($a['cores'][$i][3] ?? 0) + ($a['cores'][$i][4] ?? 0));
            $cores[] = $t > 0 ? round(100 * (1 - $idle / $t)) : 0;
        }

        $load = @file_get_contents($this->path('/proc/loadavg'));
        $avg = $load ? array_map('floatval', array_slice(explode(' ', $load), 0, 3)) : null;

        $freq = @file_get_contents($this->path('/sys/devices/system/cpu/cpu0/cpufreq/scaling_cur_freq'));

        return [
            'usage' => max(0, min(100, $usage)),
            'cores' => $cores,
            'count' => count($cores) ?: 1,
            'load'  => $avg,
            'mhz'   => $freq ? (int) round((int) trim($freq) / 1000) : null,
        ];
    }

    private function cpuTimes(): ?array
    {
        $stat = @file($this->path('/proc/stat'), FILE_IGNORE_NEW_LINES);
        if (! $stat) return null;
        $total = null;
        $cores = [];
        foreach ($stat as $line) {
            if (! str_starts_with($line, 'cpu')) break;
            $parts = preg_split('/\s+/', trim($line));
            $vals = array_map('intval', array_slice($parts, 1, 8));
            if ($parts[0] === 'cpu') $total = $vals;
            else $cores[] = $vals;
        }
        if ($total === null) return null;

        return ['total' => $total, 'idle' => $total[3] + ($total[4] ?? 0), 'cores' => $cores];
    }

    /** Оперативная память, байты. */
    public function memory(): ?array
    {
        $info = @file_get_contents($this->path('/proc/meminfo'));
        if (! $info) return null;
        $get = fn ($k) => preg_match("/^$k:\s+(\d+)/m", $info, $m) ? (int) $m[1] * 1024 : null;
        $total = $get('MemTotal');
        $avail = $get('MemAvailable');
        if (! $total || $avail === null) return null;

        return ['total' => $total, 'used' => $total - $avail, 'percent' => round(($total - $avail) / $total * 100)];
    }

    /** Сколько секунд сервер работает после включения. */
    public function uptime(): ?int
    {
        $raw = @file_get_contents($this->path('/proc/uptime'));

        return $raw ? (int) explode(' ', $raw)[0] : null;
    }
}
