<?php

namespace App\Services;

use App\Models\PgpRecord;
use App\Support\BoardTypes;
use App\Support\WorkerNames;
use Carbon\Carbon;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

/**
 * Загрузка бортов ПГП из Excel (журнал: Дата, Номер поста, ФИО 1–3, Время начала/завершения работ,
 * Номер борта, IP адрес модуля связи, Номер фюзеляжа, Сдан, Неисправен, Причина неисправности, Действия).
 *
 * Сначала parse() — разбор и предпросмотр (ничего не пишет), затем save() — запись того, что не дубль.
 * Дубль: тот же номер борта; без номера борта — та же дата + пост + № фюзеляжа + ФИО 1.
 * ФИО сопоставляются со справочником сотрудников (WorkerNames::resolve), включая опечатки в фамилии.
 */
class PgpImport
{
    /** Поле => как может называться колонка (сравнение без регистра, пробелов и знаков). */
    private const HEADERS = [
        'record_date'     => ['дата'],
        'post_number'     => ['номерпоста', 'пост', 'постномер', 'номерпост'],
        'worker1'         => ['фио1', 'фио', 'исполнитель1', 'исполнитель'],
        'worker2'         => ['фио2', 'исполнитель2'],
        'worker3'         => ['фио3', 'исполнитель3'],
        'work_start'      => ['времяначаларабот', 'времяначала', 'начало', 'началоработ'],
        'work_end'        => ['времязавершенияработ', 'времязавершения', 'окончание', 'завершение', 'времяокончания'],
        'board_number'    => ['номерборта', 'борт', 'бортномер'],
        'ip_address'      => ['ipадресмодулясвязитипаmanetmesh', 'ipадресмодулясвязи', 'ipадрес', 'ip'],
        'fuselage_number' => ['номерфюзеляжа', 'номерфезюляжа', 'номерфезеляжа', 'номерфюзиляжа', 'фюзеляж', 'фюзеляжномер'],
        'delivered'       => ['сдан', 'сдано'],
        'faulty'          => ['неисправен', 'несдан', 'брак'],
        'defect_reason'   => ['причинанеисправности', 'причина'],
        'actions_taken'   => ['действияпредпринятыедляанализаотказа', 'действияпредпринятые', 'действия'],
    ];

    private const NO = ['', '0', '-', '—', 'нет', 'no', 'false', 'ложь'];

    private WorkerNames $names;

    public function __construct()
    {
        $this->names = new WorkerNames();
    }

    /**
     * @param array<int, array<int, mixed>> $sheet строки листа как есть (первый лист файла)
     * @return array{rows: array, missing: array, error: ?string}
     */
    public function parse(array $sheet): array
    {
        [$headerIdx, $map] = $this->findHeader($sheet);
        if ($headerIdx === null) {
            return ['rows' => [], 'missing' => [], 'error' => 'Не нашли строку заголовков: нужны хотя бы колонки «Дата», «Номер поста», «ФИО 1» и «Номер борта».'];
        }

        $rows = [];
        $seen = [];   // ключи дублей внутри файла
        foreach (array_slice($sheet, $headerIdx + 1, null, true) as $i => $cells) {
            $get = fn (string $f) => isset($map[$f]) ? ($cells[$map[$f]] ?? null) : null;
            if (! array_filter($map, fn ($col) => trim((string) ($cells[$col] ?? '')) !== '')) continue;   // пустая строка

            $row = $this->row($get, $i + 1);
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

        $missing = array_keys(array_diff_key(array_flip(['record_date', 'post_number', 'worker1', 'board_number', 'delivered', 'faulty']), $map));

        return ['rows' => $rows, 'missing' => $missing, 'error' => $rows ? null : 'В файле нет строк с данными под заголовками.'];
    }

    /** Записывает строки без ошибок и не дубли (дубли перепроверяются — вдруг их внесли после предпросмотра). */
    public function save(array $rows, int $userId): array
    {
        $created = 0; $skipped = 0;
        $seen = [];
        foreach ($rows as $row) {
            if ($row['error'] || $row['duplicate']) { $skipped++; continue; }
            $key = $this->dupKey($row['data']);
            if (isset($seen[$key]) || $this->existsInDb($row['data'])) { $skipped++; continue; }
            $seen[$key] = true;

            $data = $row['data'];
            // время внесения — по дате и времени работ из журнала, чтобы запись попала в свою смену в отчётах
            $at = Carbon::parse($data['record_date'] . ' ' . ($data['work_end'] ?? $data['work_start'] ?? '12:00'));
            $record = new PgpRecord($data + ['created_by' => $userId]);
            $record->forceFill(['created_at' => $at, 'updated_at' => now()]);
            $record->save();
            $created++;
        }

        return ['created' => $created, 'skipped' => $skipped];
    }

    private function row(callable $get, int $line): array
    {
        $warnings = [];
        $workers = [];
        $data = [];

        $date = $this->date($get('record_date'));
        if (! $date) {
            return ['line' => $line, 'data' => [], 'workers' => [], 'warnings' => [], 'duplicate' => null,
                    'error' => 'нет даты или дата не распознана: «' . trim((string) $get('record_date')) . '»'];
        }
        $data['record_date'] = $date;

        $post = trim((string) $get('post_number'));
        if (preg_match('/^\d+(\.0+)?$/', $post) && (int) $post >= 1 && (int) $post <= 30) {
            $data['post_number'] = (int) $post;
        } else {
            $data['post_number'] = null;
            if ($post !== '') $warnings[] = 'пост «' . $post . '» не распознан';
        }

        $n = 0;
        foreach (['worker1', 'worker2', 'worker3'] as $f) {
            foreach (WorkerNames::split((string) $get($f)) as $raw) {
                $res = $this->names->resolve($raw);
                if (! $res || $n >= 5) continue;
                $n++;
                $data['worker' . $n] = mb_substr($res['name'], 0, 120);
                $workers[] = ['raw' => $raw, 'name' => $res['name'], 'kind' => $res['kind']];
            }
        }
        if (! $n) {
            return ['line' => $line, 'data' => $data, 'workers' => [], 'warnings' => $warnings, 'duplicate' => null,
                    'error' => 'не указан ни один исполнитель'];
        }

        foreach (['work_start', 'work_end'] as $f) {
            $raw = $get($f);
            $t = $this->time($raw);
            $data[$f] = $t;
            if (! $t && trim((string) $raw) !== '') $warnings[] = 'время «' . trim((string) $raw) . '» не распознано';
        }

        $board = $this->text($get('board_number'), 80);
        $data['board_number'] = $board;
        if ($board) {
            $data['board_type'] = BoardTypes::detect($board);
            if (! $data['board_type']) $warnings[] = 'тип борта не определился по номеру';
        }

        $ip = $this->text($get('ip_address'), 64);
        if ($ip !== null && preg_match('/^\d{1,3}$/', $ip)) $ip = '192.168.1.' . $ip;
        if ($ip !== null && ! preg_match('/^192\.168\.1\.(25[0-4]|2[0-4]\d|1\d\d|[1-9]\d?)$/', $ip)) $warnings[] = 'IP «' . $ip . '» нестандартный';
        $data['ip_address'] = $ip;

        $data['fuselage_number'] = $this->text($get('fuselage_number'), 64);
        $data['defect_reason']   = $this->text($get('defect_reason'), 5000);
        $data['actions_taken']   = $this->text($get('actions_taken'), 5000);

        $delivered = $this->yes($get('delivered'));
        $faulty = $this->yes($get('faulty'));
        if ($faulty && $delivered) $warnings[] = 'отмечены и «Сдан», и «Неисправен» — записан «Не сдан»';
        if (! $faulty && ! $delivered) {
            $faulty = $data['defect_reason'] !== null;
            $warnings[] = 'не отмечено ни «Сдан», ни «Неисправен» — ' . ($faulty ? 'есть причина, записан «Не сдан»' : 'записан «Сдан»');
        }
        $data['status'] = $faulty ? 'Не сдан' : 'Сдан';

        foreach ($workers as $w) {
            if ($w['kind'] === 'unknown') $warnings[] = '«' . $w['raw'] . '» нет в справочнике сотрудников';
        }

        return ['line' => $line, 'data' => $data, 'workers' => $workers, 'warnings' => $warnings, 'duplicate' => null, 'error' => null];
    }

    /** @return array{0: ?int, 1: array<string, int>} номер строки заголовков и поле => номер колонки */
    private function findHeader(array $sheet): array
    {
        foreach (array_slice($sheet, 0, 15, true) as $i => $cells) {
            $map = [];
            foreach ($cells as $col => $v) {
                $h = $this->norm((string) $v);
                if ($h === '') continue;
                foreach (self::HEADERS as $field => $aliases) {
                    if (! isset($map[$field]) && in_array($h, $aliases, true)) { $map[$field] = $col; break; }
                }
            }
            if (isset($map['record_date'], $map['worker1']) && (isset($map['board_number']) || isset($map['post_number']))) {
                return [$i, $map];
            }
        }

        return [null, []];
    }

    private function norm(string $s): string
    {
        $s = mb_strtolower(str_replace(['ё', 'Ё'], 'е', $s));
        return preg_replace('/[^\p{L}\d]+/u', '', $s);
    }

    private function text($v, int $max): ?string
    {
        if ($v instanceof \DateTimeInterface) $v = $v->format('d.m.Y');
        $v = trim(preg_replace('/[ \t\x{00A0}]+/u', ' ', (string) $v));
        if (is_numeric($v) && str_ends_with($v, '.0')) $v = substr($v, 0, -2);
        return $v === '' ? null : mb_substr($v, 0, $max);
    }

    private function yes($v): bool
    {
        if (is_bool($v)) return $v;
        return ! in_array(mb_strtolower(trim((string) $v)), self::NO, true);
    }

    private function date($v): ?string
    {
        try {
            if ($v instanceof \DateTimeInterface) return Carbon::instance($v)->toDateString();
            if (is_numeric($v) && $v > 20000 && $v < 80000) return Carbon::instance(ExcelDate::excelToDateTimeObject($v))->toDateString();
            $s = trim((string) $v);
            if ($s === '') return null;
            foreach (['d.m.Y', 'd.m.y', 'Y-m-d', 'd/m/Y', 'd-m-Y'] as $f) {
                $d = \DateTime::createFromFormat('!' . $f, preg_replace('/\s.*$/', '', $s));
                if ($d) return Carbon::instance($d)->toDateString();
            }
        } catch (\Throwable) {
        }

        return null;
    }

    private function time($v): ?string
    {
        if ($v instanceof \DateTimeInterface) return $v->format('H:i');
        if (is_numeric($v) && (float) $v == (int) $v) {   // целое: «9» — это 09:00, а целое ≥ 24 — дата без времени
            return (int) $v < 24 && (int) $v >= 0 ? sprintf('%02d:00', (int) $v) : null;
        }
        if (is_numeric($v) && $v >= 0) {
            $minutes = (int) round(fmod((float) $v, 1) * 1440) % 1440;   // доля суток (с датой или без)
            return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
        }
        if (preg_match('/(\d{1,2})[:.](\d{2})/', (string) $v, $m) && (int) $m[1] < 24 && (int) $m[2] < 60) {
            return sprintf('%02d:%02d', $m[1], $m[2]);
        }

        return null;
    }

    private function dupKey(array $d): string
    {
        if (! empty($d['board_number'])) return 'b:' . mb_strtolower($d['board_number']);

        return 'r:' . implode('|', [$d['record_date'], $d['post_number'] ?? '', mb_strtolower((string) ($d['fuselage_number'] ?? '')), mb_strtolower($d['worker1'] ?? '')]);
    }

    /** Текст причины, если такая запись уже есть в базе, иначе null. */
    private function existsInDb(array $d): ?string
    {
        if (! empty($d['board_number'])) {
            $id = PgpRecord::where('board_number', $d['board_number'])->value('id');
            return $id ? 'борт уже есть в базе (запись #' . $id . ')' : null;
        }

        $q = PgpRecord::whereDate('record_date', $d['record_date'])->where('worker1', $d['worker1']);
        $d['post_number'] === null ? $q->whereNull('post_number') : $q->where('post_number', $d['post_number']);
        empty($d['fuselage_number'])
            ? $q->where(fn ($w) => $w->whereNull('fuselage_number')->orWhere('fuselage_number', ''))
            : $q->where('fuselage_number', $d['fuselage_number']);
        $id = $q->value('id');

        return $id ? 'такая запись уже есть (запись #' . $id . ')' : null;
    }
}
