<?php

namespace App\Services;

use Carbon\Carbon;

/**
 * Чтение ошибок из логов Laravel (storage/logs/*.log) для страницы «Ошибки».
 * Читается только конец свежих файлов — лог может быть большим, а сервер слабый.
 */
class LogReader
{
    private const LEVELS = 'ERROR|CRITICAL|ALERT|EMERGENCY';
    private const TAIL_BYTES = 5 * 1024 * 1024;
    private const MAX_TRACE = 40;

    /**
     * Ошибки за последние $hours часов, новые сверху.
     *
     * @return array<int, array{time: string, level: string, message: string, exception: ?string, location: ?string, user: ?int, trace: string[]}>
     */
    public function errors(int $hours = 24): array
    {
        $since = now()->subHours($hours);
        $files = collect(glob(storage_path('logs/*.log')) ?: [])
            ->sortByDesc(fn ($f) => filemtime($f))
            ->filter(fn ($f) => filemtime($f) >= $since->getTimestamp())
            ->take(3);

        $out = [];
        foreach ($files as $file) {
            foreach ($this->parse($file) as $e) {
                if (Carbon::parse($e['time'])->gte($since)) $out[] = $e;
            }
        }
        usort($out, fn ($a, $b) => strcmp($b['time'], $a['time']));

        return $out;
    }

    /**
     * Одинаковые ошибки — одной строкой: сколько раз, первая и последняя, подробности последней.
     */
    public function grouped(int $hours = 24): array
    {
        $groups = [];
        foreach ($this->errors($hours) as $e) {
            $key = md5($e['level'] . $e['message']);
            if (! isset($groups[$key])) {
                $groups[$key] = $e + ['count' => 0, 'first' => $e['time'], 'last' => $e['time'], 'users' => []];
            }
            $g = &$groups[$key];
            $g['count']++;
            $g['first'] = min($g['first'], $e['time']);
            // Подробности — из самого свежего случая, где они есть
            foreach (['exception', 'location'] as $f) $g[$f] ??= $e[$f];
            if (! $g['trace'] && $e['trace']) $g['trace'] = $e['trace'];
            if ($e['user']) $g['users'][$e['user']] = true;
            unset($g);
        }

        return array_values(array_map(function ($g) {
            $g['users'] = array_keys($g['users']);
            return $g;
        }, $groups));
    }

    private function parse(string $file): array
    {
        $fh = @fopen($file, 'rb');
        if (! $fh) return [];
        $size = filesize($file);
        if ($size > self::TAIL_BYTES) {
            fseek($fh, $size - self::TAIL_BYTES);
            fgets($fh);   // первая строка обрезана — пропускаем
        }

        $entries = [];
        $current = null;
        while (($line = fgets($fh)) !== false) {
            $line = rtrim($line, "\r\n");
            if (preg_match('/^\[(\d{4}-\d\d-\d\d[ T]\d\d:\d\d:\d\d)[^\]]*\] \w+\.(\w+): (.*)$/u', $line, $m)) {
                if ($current) $entries[] = $current;
                $current = null;
                if (! preg_match('/^(' . self::LEVELS . ')$/', $m[2])) continue;
                $current = $this->header($m[1], $m[2], $m[3]);
            } elseif ($current && count($current['trace']) < self::MAX_TRACE && preg_match('/^#\d+ /', $line)) {
                $current['trace'][] = mb_strimwidth($this->shortPath($line), 0, 400, '…');
            }
        }
        if ($current) $entries[] = $current;
        fclose($fh);

        return $entries;
    }

    /** Путь относительно папки сайта — короче и понятнее (и на Windows, и на Linux). */
    private function shortPath(string $s): string
    {
        $base = rtrim(base_path(), '/\\');

        return str_replace([$base . DIRECTORY_SEPARATOR, str_replace('\\', '/', $base) . '/'], '', $s);
    }

    private function header(string $time, string $level, string $rest): array
    {
        // «Текст ошибки {"userId":1,"exception":"[object] (Класс(code: 0): текст at /путь/файл.php:42)"}»
        $message = $rest;
        $context = '';
        if (($pos = strpos($rest, ' {"')) !== false) {
            $message = substr($rest, 0, $pos);
            $context = substr($rest, $pos);
        }
        // Повторы «(View: …)» в тексте — оставляем один
        $message = preg_replace('/(\s*\(View: [^)]+\))+/u', '$1', $message);

        $exception = $location = null;
        if (preg_match('/\[object\] \(([^(]+)\(code: [^)]*\): .* at (.+?:\d+)\)/u', $context, $m)) {
            $exception = stripslashes($m[1]);
            $location = $this->shortPath(stripslashes($m[2]));
        }
        $user = preg_match('/"userId":(\d+)/', $context, $u) ? (int) $u[1] : null;

        return [
            'time'      => str_replace('T', ' ', $time),
            'level'     => $level,
            'message'   => mb_strimwidth(trim($message), 0, 600, '…'),
            'exception' => $exception,
            'location'  => $location,
            'user'      => $user,
            'trace'     => [],
        ];
    }
}
