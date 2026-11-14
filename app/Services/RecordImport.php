<?php

namespace App\Services;

use App\Support\WorkerNames;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

/**
 * Загрузка записей любой вкладки из таблицы (xlsx / xls / ods / csv) — по настройкам вкладки из config/sheets.php.
 *
 * Колонки файла сопоставляются с полями вкладки по названию (подпись поля, «ФИО 1» = «Исполнитель 1» и т.п.);
 * колонки, которых во вкладке нет, пропускаются — новые поля и вкладки не создаются.
 * Дубль — в базе (или выше в файле) уже есть запись с теми же датой и значениями всех заполненных полей.
 * ПГП грузится своим разбором (PgpImport) — у журнала бортов особые колонки «Сдан» / «Неисправен».
 */
class RecordImport
{
    private const WORKERS = ['worker1', 'worker2', 'worker3', 'worker4', 'worker5'];

    /** Другие привычные названия колонок (сравнение без регистра, пробелов и знаков). */
    private const ALIASES = [
        'record_date' => ['дата', 'датазаписи', 'число'],
        'qty'         => ['колво', 'количество', 'колвозадень', 'шт', 'штук'],
        'worker1'     => ['фио1', 'фио', 'исполнитель', 'исполнитель1', 'исполнители', 'сотрудник'],
        'worker2'     => ['фио2', 'исполнитель2'],
        'worker3'     => ['фио3', 'исполнитель3'],
        'worker4'     => ['фио4', 'исполнитель4'],
        'worker5'     => ['фио5', 'исполнитель5'],
        'notes'       => ['примечание', 'примечания', 'комментарий'],
        'fault_code'  => ['коднеисправности', 'кодотказа'],
        'serial_number' => ['sn', 'серийныйномер', 'заводскойномер', 'серийныйн'],
        'camera_serial' => ['номеркамеры', 'камера', 'snкамеры'],
        'work_start'  => ['времяначала', 'времяначаларабот', 'начало'],
        'work_end'    => ['времязавершения', 'времязавершенияработ', 'окончание'],
    ];

    private const YES = ['1', 'да', 'yes', 'true', '+', 'v', '✓', 'истина', 'исправен', 'работает'];
    private const NO  = ['0', 'нет', 'no', 'false', '-', '—', 'ложь'];

    private WorkerNames $names;

    public function __construct(private string $slug, private array $cfg)
    {
        $this->names = new WorkerNames();
    }

    /** Колонки таблицы предпросмотра: поле => подпись. */
    public function previewColumns(): array
    {
        $cols = [];
        foreach ($this->cfg['list_columns'] ?? ['record_date'] as $f) {
            if (isset($this->cfg['fields'][$f]) && ! in_array($f, self::WORKERS, true)) $cols[$f] = $this->cfg['fields'][$f]['label'];
        }
        return $cols;
    }

    public function hasHeader(array $sheet): bool
    {
        return $this->findHeader($sheet)[0] !== null;
    }

    /** Лист, названный как вкладка (и с её колонками), иначе первый лист с колонками вкладки, иначе первый. */
    public function pickSheet(array $sheets): ?string
    {
        $withHeader = array_keys(array_filter($sheets, fn ($rows) => $this->hasHeader($rows)));
        $title = $this->norm(preg_replace('/\(.*\)/u', '', $this->cfg['title']));
        foreach ($withHeader as $name) {
            $n = $this->norm((string) $name);
            if ($n !== '' && $title !== '' && (str_contains($n, $title) || str_contains($title, $n))) return (string) $name;
        }

        return $withHeader[0] ?? (array_key_first($sheets) === null ? null : (string) array_key_first($sheets));
    }

    /** @return array{rows: array, missing: array, error: ?string} */
    public function parse(array $sheet): array
    {
        [$headerIdx, $map] = $this->findHeader($sheet);
        if ($headerIdx === null) {
            return ['rows' => [], 'missing' => [], 'error' => 'не нашли строку заголовков с колонками вкладки «' . $this->cfg['title']
                . '» — нужны хотя бы «Дата» и ещё одна колонка: ' . implode(', ', array_slice($this->labels(), 0, 8)) . '…'];
        }

        $rows = []; $seen = [];
        foreach (array_slice($sheet, $headerIdx + 1, null, true) as $i => $cells) {
            if (! array_filter($map, fn ($col) => trim((string) ($cells[$col] ?? '')) !== '')) continue;
            $row = $this->row(fn (string $f) => isset($map[$f]) ? ($cells[$map[$f]] ?? null) : null, $i + 1, $map);
            if (! $row['error']) {
                $key = $this->dupKey($row['data']);
                if (isset($seen[$key])) {
                    $row['duplicate'] = 'повтор строки ' . $seen[$key] . ' в файле';
                } else {
                    $seen[$key] = $row['line'];
                    $row['duplicate'] = $this->existsInDb($row['data']);
                }
            }
            $rows[] = $row;
        }

        // обязательные поля вкладки, для которых в файле нет колонки
        $missing = [];
        foreach ($this->cfg['fields'] as $f => $fc) {
            if (! isset($map[$f]) && $this->required($fc) && ! in_array($f, ['qty'], true)) $missing[] = $fc['label'];
        }

        return ['rows' => $rows, 'missing' => $missing, 'error' => $rows ? null : 'под заголовками нет строк с данными'];
    }

    public function save(array $rows, int $userId): array
    {
        $created = 0; $skipped = 0; $seen = [];
        $model = $this->cfg['model'];
        $fillable = array_flip((new $model)->getFillable());
        foreach ($rows as $row) {
            if ($row['error'] || $row['duplicate']) { $skipped++; continue; }
            $key = $this->dupKey($row['data']);
            if (isset($seen[$key]) || $this->existsInDb($row['data'])) { $skipped++; continue; }
            $seen[$key] = true;

            $data = array_intersect_key($row['data'], $fillable);
            if (array_key_exists('fault_code', $data) && $data['fault_code'] !== null) {
                $data['fault_code'] = app(FaultCodes::class)->resolve($this->slug, $data['fault_code'], $userId);
            }
            $time = $data['work_end'] ?? $data['work_start'] ?? '12:00';
            $record = new $model($data + (isset($fillable['created_by']) ? ['created_by' => $userId] : []));
            $record->forceFill(['created_at' => Carbon::parse($data['record_date'] . ' ' . $time), 'updated_at' => now()]);
            try {
                $record->save();
                $created++;
            } catch (QueryException $e) {
                if (! in_array($e->errorInfo[1] ?? null, [1062, 19], true)) throw $e;   // уникальный индекс — тоже дубль
                $skipped++;
            }
        }

        return ['created' => $created, 'skipped' => $skipped];
    }

    // ---------------------------------------------------------------------

    private function row(callable $get, int $line, array $map): array
    {
        $data = []; $workers = []; $warnings = [];
        $fail = function (string $msg) use ($line, &$data, &$workers, &$warnings) {
            return ['line' => $line, 'data' => $data, 'workers' => $workers, 'warnings' => $warnings, 'duplicate' => null, 'error' => $msg];
        };

        $date = $this->date($get('record_date'));
        if (! $date) return $fail('нет даты или дата не распознана: «' . trim((string) $get('record_date')) . '»');
        $data['record_date'] = $date;

        // исполнители: из колонок «ФИО 1…5», в одной ячейке может быть несколько через запятую
        $n = 0;
        foreach (self::WORKERS as $f) {
            if (! isset($this->cfg['fields'][$f])) continue;
            foreach (WorkerNames::split((string) $get($f)) as $raw) {
                $res = $this->names->resolve($raw);
                if (! $res || $n >= 5) continue;
                $n++;
                $data['worker' . $n] = mb_substr($res['name'], 0, 120);
                $workers[] = ['raw' => $raw, 'name' => $res['name'], 'kind' => $res['kind']];
                if ($res['kind'] === 'unknown') $warnings[] = '«' . $raw . '» нет в справочнике сотрудников';
            }
        }
        if (isset($this->cfg['fields']['worker1']) && ! $n) return $fail('не указан ни один исполнитель');

        foreach ($this->cfg['fields'] as $f => $fc) {
            if ($f === 'record_date' || in_array($f, self::WORKERS, true) || ! isset($map[$f])) {
                if ($f === 'qty' && ! isset($map[$f])) $data['qty'] = 1;   // нет колонки количества — одна штука на строку
                continue;
            }
            $raw = $get($f);
            $empty = trim((string) $raw) === '';
            [$value, $warn] = $empty ? [null, null] : $this->value($f, $fc, $raw);
            if ($warn) $warnings[] = $fc['label'] . ': ' . $warn;
            if ($value === null && $f === 'qty') { $value = 1; if (! $empty) $warnings[] = 'количество не распознано — записано 1'; }
            if ($value === null && $this->required($fc)) {
                return $fail('не заполнено обязательное поле «' . $fc['label'] . '»' . ($empty ? '' : ': «' . trim((string) $raw) . '»'));
            }
            $data[$f] = $value;
        }

        return ['line' => $line, 'data' => $data, 'workers' => $workers, 'warnings' => $warnings, 'duplicate' => null, 'error' => null];
    }

    /** @return array{0: mixed, 1: ?string} значение и замечание */
    private function value(string $f, array $fc, $raw): array
    {
        $type = $fc['type'] ?? 'text';
        $isBool = ! empty($fc['bool']) || $type === 'checkbox' || array_keys($fc['options'] ?? []) === [1, 0];   // ['1'=>'Да','0'=>'Нет']
        $s = trim(preg_replace('/[ \t\x{00A0}]+/u', ' ', $raw instanceof \DateTimeInterface ? $raw->format('d.m.Y') : (string) $raw));
        if (is_numeric($s) && str_ends_with($s, '.0')) $s = substr($s, 0, -2);
        $low = mb_strtolower($s);

        if ($isBool) {
            if (in_array($low, self::YES, true) || $low === mb_strtolower((string) ($fc['options']['1'] ?? $fc['options'][1] ?? 'да'))) return [1, null];
            if (in_array($low, self::NO, true) || $low === mb_strtolower((string) ($fc['options']['0'] ?? $fc['options'][0] ?? 'нет'))) return [0, null];
            return [null, '«' . $s . '» — не «да» / «нет»'];
        }

        switch ($type) {
            case 'date':
                $d = $this->date($raw);
                return [$d, $d ? null : '«' . $s . '» — не дата'];
            case 'time':
                $t = $this->time($raw);
                return [$t, $t ? null : '«' . $s . '» — не время'];
            case 'number':
                if (preg_match('/^-?\d+([.,]0+)?$/', $s)) return [(int) $s, null];
                return [null, '«' . $s . '» — не число'];
            case 'ip_suffix':
                $ip = preg_match('/^\d{1,3}$/', $s) ? ($fc['prefix'] ?? '') . $s : $s;
                return [mb_substr($ip, 0, 64), null];
            case 'select':
                $options = $fc['options'] ?? [];
                foreach ($options as $k => $label) {
                    if ($low === mb_strtolower((string) $label) || (is_string($k) && $low === mb_strtolower($k))) return [is_int($k) ? $label : $k, null];
                }
                if (count($options) === 2) {   // «да / 1 / +» — первый вариант («Исправен», «Работает»), «нет / 0» — второй
                    $vals = array_map(fn ($k, $v) => is_int($k) ? $v : $k, array_keys($options), $options);
                    if (in_array($low, self::YES, true)) return [$vals[0], null];
                    if (in_array($low, self::NO, true)) return [$vals[1], null];
                }
                return [null, '«' . $s . '» нет среди вариантов (' . implode(', ', $options) . ') — поле не заполнено'];
        }

        if (($fc['options_from'] ?? null) === 'employees') {   // «Кто собирал» и т.п. — как исполнитель, по справочнику
            $res = $this->names->resolve($s);
            if ($res) return [mb_substr($res['name'], 0, 120), $res['kind'] === 'unknown' ? '«' . $s . '» нет в справочнике сотрудников' : null];
        }

        return [mb_substr($s, 0, $this->maxLen($fc)), mb_strlen($s) > $this->maxLen($fc) ? 'обрезано до ' . $this->maxLen($fc) . ' символов' : null];
    }

    private function required(array $fc): bool
    {
        $rules = is_array($fc['rules'] ?? null) ? $fc['rules'] : explode('|', (string) ($fc['rules'] ?? ''));
        return in_array('required', $rules, true);
    }

    private function maxLen(array $fc): int
    {
        $rules = is_array($fc['rules'] ?? null) ? implode('|', array_filter($fc['rules'], 'is_string')) : (string) ($fc['rules'] ?? '');
        return preg_match('/max:(\d+)/', $rules, $m) ? (int) $m[1] : 10000;
    }

    private function labels(): array
    {
        return array_values(array_map(fn ($fc) => $fc['label'], array_filter($this->cfg['fields'], fn ($fc, $f) => ! in_array($f, ['worker2', 'worker3', 'worker4', 'worker5'], true), ARRAY_FILTER_USE_BOTH)));
    }

    /** @return array{0: ?int, 1: array<string, int>} */
    private function findHeader(array $sheet): array
    {
        $aliases = [];
        foreach ($this->cfg['fields'] as $f => $fc) {
            $aliases[$f] = array_values(array_unique(array_filter(array_merge([$this->norm($fc['label'])], self::ALIASES[$f] ?? []))));
        }

        foreach (array_slice($sheet, 0, 15, true) as $i => $cells) {
            $map = [];
            $headers = [];
            foreach ($cells as $col => $v) {
                $h = $this->norm((string) $v);
                if ($h !== '') $headers[$col] = $h;
            }
            // 1) точное совпадение названия
            foreach ($headers as $col => $h) {
                foreach ($aliases as $f => $list) {
                    if (! isset($map[$f]) && in_array($h, $list, true)) { $map[$f] = $col; continue 2; }
                }
            }
            // 2) начало названия: «Заводской номер» → «Заводской №», «Примечание мастера» → «Примечание»
            $used = array_flip($map);
            foreach ($headers as $col => $h) {
                if (isset($used[$col]) || mb_strlen($h) < 5) continue;
                $hits = array_keys(array_filter($aliases, fn ($list, $f) => ! isset($map[$f]) && array_filter($list,
                    fn ($a) => mb_strlen($a) >= 5 && (str_starts_with($h, $a) || str_starts_with($a, $h))), ARRAY_FILTER_USE_BOTH));
                if (count($hits) === 1) { $map[$hits[0]] = $col; $used[$col] = true; }
            }

            // кроме даты нужна хоть одна «своя» колонка вкладки — дата + ФИО есть почти в любом журнале
            $own = array_diff_key($map, array_flip(array_merge(['record_date', 'notes', 'fault_code'], self::WORKERS)));
            if (isset($map['record_date']) && $own) return [$i, $map];
        }

        return [null, []];
    }

    private function norm(string $s): string
    {
        $s = mb_strtolower(str_replace(['ё', 'Ё'], 'е', $s));
        return preg_replace('/[^\p{L}\d]+/u', '', $s);
    }

    private function date($v): ?string
    {
        try {
            if ($v instanceof \DateTimeInterface) return Carbon::instance($v)->toDateString();
            if (is_numeric($v) && $v > 20000 && $v < 80000) return Carbon::instance(ExcelDate::excelToDateTimeObject($v))->toDateString();
            $s = preg_replace('/\s.*$/', '', trim((string) $v));
            foreach (['d.m.Y', 'd.m.y', 'Y-m-d', 'd/m/Y', 'd-m-Y'] as $f) {
                if ($s !== '' && ($d = \DateTime::createFromFormat('!' . $f, $s))) return Carbon::instance($d)->toDateString();
            }
        } catch (\Throwable) {
        }

        return null;
    }

    private function time($v): ?string
    {
        if ($v instanceof \DateTimeInterface) return $v->format('H:i');
        if (is_numeric($v) && (float) $v == (int) $v) return (int) $v >= 0 && (int) $v < 24 ? sprintf('%02d:00', (int) $v) : null;
        if (is_numeric($v) && $v >= 0) {
            $m = (int) round(fmod((float) $v, 1) * 1440) % 1440;
            return sprintf('%02d:%02d', intdiv($m, 60), $m % 60);
        }
        if (preg_match('/(\d{1,2})[:.](\d{2})/', (string) $v, $m) && (int) $m[1] < 24 && (int) $m[2] < 60) return sprintf('%02d:%02d', $m[1], $m[2]);

        return null;
    }

    private function dupKey(array $d): string
    {
        ksort($d);
        return md5(mb_strtolower(json_encode(array_filter($d, fn ($v) => $v !== null && $v !== ''), JSON_UNESCAPED_UNICODE)));
    }

    /** Та же запись уже есть: совпадают дата и все заполненные поля строки. */
    private function existsInDb(array $d): ?string
    {
        $q = $this->cfg['model']::query()->whereDate('record_date', $d['record_date']);
        foreach ($d as $f => $v) {
            if ($f === 'record_date' || $v === null || $v === '' || $f === 'fault_code') continue;
            in_array($this->cfg['fields'][$f]['type'] ?? '', ['time'], true)
                ? $q->whereTime($f, $v . ':00')
                : $q->where($f, $v);
        }
        $id = $q->value('id');

        return $id ? 'такая запись уже есть (запись #' . $id . ')' : null;
    }
}
