<?php

namespace App\Services;

use Carbon\Carbon;

/**
 * Анализ брака по текущему участку: частые причины, коды неисправностей, неисправные узлы,
 * исполнители и динамика по неделям. Брак — по общему правилу StatsService::defectSql.
 */
class DefectAnalysis
{
    private const WORKERS = ['worker1', 'worker2', 'worker3', 'worker4', 'worker5'];
    private const TOP = 15;

    public function __construct(private SectionContext $sections, private StatsService $stats) {}

    public function build(Carbon $from, Carbon $to, ?string $only = null): array
    {
        $reasons = $codes = $parts = $workers = $weeks = [];
        $total = $defects = 0;
        $bySheet = [];
        $names = new \App\Support\WorkerNames();   // разные написания одного ФИО — один исполнитель

        foreach ($this->sections->sheets() as $slug => $cfg) {
            if (empty($cfg['model']) || ($only && $only !== $slug)) continue;
            $fields = $cfg['fields'] ?? [];
            $rule = $this->stats->defectSql($cfg)['defect'];
            if (! $rule) continue;   // во вкладке нет понятия брака

            $dateField = isset($fields['record_date']) ? 'record_date' : 'created_at';
            $wf = array_values(array_filter(self::WORKERS, fn ($f) => isset($fields[$f])));
            $reasonFields = array_keys(array_filter($fields, fn ($f, $n) => ($f['type'] ?? '') === 'textarea'
                && preg_match('/(defect|reason|дефект|причин)/iu', $n . ' ' . ($f['label'] ?? '')), ARRAY_FILTER_USE_BOTH));
            $partFields = array_keys(array_filter($fields, fn ($f) => ($f['type'] ?? '') === 'select'
                && array_intersect($f['options'] ?? [], StatsService::DEFECT_VALUES)));

            $rows = $cfg['model']::query()
                ->whereBetween($dateField, [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
                ->selectRaw("*, CASE WHEN {$rule['sql']} THEN 1 ELSE 0 END AS is_defect", $rule['bindings'])
                ->get();
            if ($rows->isEmpty()) continue;

            $sheetDefects = 0;
            foreach ($rows as $row) {
                $isDefect = (int) $row->is_defect === 1;
                $total++;
                $week = Carbon::parse($row->{$dateField})->startOfWeek()->format('Y-m-d');
                $weeks[$week] ??= ['total' => 0, 'defect' => 0];
                $weeks[$week]['total']++;

                foreach ($this->workerIds($row, $wf, $names) as $id) {
                    $workers[$id] ??= ['total' => 0, 'defect' => 0];
                    $workers[$id]['total']++;
                    if ($isDefect) $workers[$id]['defect']++;
                }

                if (! $isDefect) continue;
                $defects++;
                $sheetDefects++;
                $weeks[$week]['defect']++;

                foreach ($reasonFields as $f) {
                    $this->bump($reasons, (string) $row->{$f}, $cfg['title']);
                }
                if (isset($fields['fault_code'])) {
                    $this->bump($codes, (string) $row->fault_code, $cfg['title']);
                }
                foreach ($partFields as $f) {
                    $v = (string) $row->{$f};
                    if (in_array($v, StatsService::DEFECT_VALUES, true)) {
                        $this->bump($parts, ($fields[$f]['label'] ?? $f) . ': ' . $v, $cfg['title']);
                    }
                }
            }
            $bySheet[$slug] = ['title' => $cfg['title'], 'total' => $rows->count(), 'defect' => $sheetDefects];
        }

        ksort($weeks);
        $workers = array_filter($workers, fn ($w) => $w['defect'] > 0);
        uasort($workers, fn ($a, $b) => [$b['defect'] / max(1, $b['total']), $b['defect']] <=> [$a['defect'] / max(1, $a['total']), $a['defect']]);
        $workers = $this->labelled($workers, $names);

        return [
            'total'   => $total,
            'defect'  => $defects,
            'reasons' => $this->top($reasons),
            'codes'   => $this->top($codes),
            'parts'   => $this->top($parts),
            'workers' => $workers,
            'weeks'   => $weeks,
            'sheets'  => $bySheet,
        ];
    }

    /** Исполнители записи (без повторов): каждое поле может содержать несколько ФИО. */
    private function workerIds($row, array $fields, \App\Support\WorkerNames $names): array
    {
        $ids = [];
        foreach ($fields as $f) {
            foreach (\App\Support\WorkerNames::split((string) $row->{$f}) as $raw) {
                if ($id = $names->id($raw)) $ids[$id] = true;
            }
        }

        return array_keys($ids);
    }

    /** [ключ => данные] → [ФИО для показа => данные + known (есть в справочнике) + variants (другие написания)]. */
    private function labelled(array $workers, \App\Support\WorkerNames $names): array
    {
        $out = [];
        foreach ($workers as $id => $w) {
            $out[$names->label($id)] = $w + ['known' => $names->known($id), 'variants' => $names->variants($id)];
        }

        return $out;
    }

    /** Один и тот же текст в разном регистре и с разными пробелами — одна причина. */
    private function bump(array &$bucket, string $value, string $sheet): void
    {
        $value = trim(preg_replace('/\s+/u', ' ', $value));
        if ($value === '' || $value === '-' || $value === '—') return;
        $key = mb_strtolower(rtrim($value, '.;,'));
        $bucket[$key] ??= ['text' => $value, 'count' => 0, 'sheets' => []];
        $bucket[$key]['count']++;
        $bucket[$key]['sheets'][$sheet] = true;
    }

    private function top(array $bucket): array
    {
        uasort($bucket, fn ($a, $b) => $b['count'] <=> $a['count']);

        return array_map(fn ($r) => ['text' => $r['text'], 'count' => $r['count'], 'sheets' => array_keys($r['sheets'])],
            array_values(array_slice($bucket, 0, self::TOP)));
    }
}
