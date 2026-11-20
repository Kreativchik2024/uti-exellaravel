<?php

namespace App\Services;

use App\Models\ResearchRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Связь модуля по серийному номеру в обе стороны:
 *  - исследование (ремонт) → где модуль делали: записи вкладок учёта с тем же S/N, кто шил / собирал;
 *  - запись вкладки (модуль связи, кросс 3, кронштейн…) → исследования этого модуля.
 * Номер сравнивается без пробелов по краям и без учёта регистра.
 */
class ModuleTrail
{
    /** Поля вкладок учёта с серийным номером модуля. */
    public const FIELDS = ['serial_number', 'camera_serial', 'manet_number', 'cross3_number', 'bracket_serial', 'frame_serial'];

    /** Поля исследования, по которым ищем модуль. */
    public const RESEARCH_FIELDS = ['module_serial', 'frame_serial', 'bracket_serial'];

    private const LIMIT = 20;

    /** Условие «одно из полей = один из номеров» без учёта регистра и пробелов по краям. */
    private static function matchAny($q, array $fields, array $numbers): void
    {
        $lower = array_map('mb_strtolower', $numbers);
        $marks = implode(',', array_fill(0, count($lower), '?'));
        $q->where(function ($w) use ($fields, $lower, $marks) {
            foreach ($fields as $f) $w->orWhereRaw("LOWER(TRIM(`$f`)) IN ($marks)", $lower);
        });
    }

    public function __construct(private SectionContext $sections) {}

    /** Серийные номера записи: [поле => номер]. */
    private static function serials(Model $record, array $fields): array
    {
        $out = [];
        foreach ($fields as $f) {
            $v = trim((string) $record->getAttribute($f));
            if ($v !== '' && mb_strlen($v) >= 3) $out[$f] = $v;
        }

        return $out;
    }

    /**
     * Где делали модуль из исследования.
     * @return Collection<int, array{sheet: string, title: string, record: Model, field: string, serial: string, workers: string}>
     */
    public function madeFor(ResearchRecord $research): Collection
    {
        $numbers = array_values(array_unique(self::serials($research, self::RESEARCH_FIELDS)));
        if (! $numbers) return collect();

        $out = collect();
        foreach ($this->sections->sheets() as $slug => $cfg) {
            if ($slug === 'research' || empty($cfg['model'])) continue;
            $fields = array_values(array_intersect(self::FIELDS, array_keys($cfg['fields'] ?? [])));
            if (! $fields) continue;

            $cfg['model']::query()
                ->tap(fn ($q) => self::matchAny($q, $fields, $numbers))
                ->orderByDesc('record_date')->orderByDesc('id')->limit(self::LIMIT)->get()
                ->each(function ($rec) use ($slug, $cfg, $fields, $numbers, $out) {
                    $field = collect($fields)->first(fn ($f) => in_array(mb_strtolower(trim((string) $rec->$f)), array_map('mb_strtolower', $numbers), true)) ?? $fields[0];
                    $out->push([
                        'sheet'   => $slug,
                        'title'   => $cfg['title'] ?? $slug,
                        'record'  => $rec,
                        'field'   => $cfg['fields'][$field]['label'] ?? $field,
                        'serial'  => (string) $rec->$field,
                        'workers' => self::workers($rec),
                    ]);
                });
        }

        return $out->sortByDesc(fn ($x) => [$x['record']->record_date?->timestamp ?? 0, $x['record']->id])->values();
    }

    /** Исследования модуля из записи вкладки учёта. @return Collection<int, ResearchRecord> */
    public function researchFor(string $sheet, Model $record): Collection
    {
        if ($sheet === 'research') return collect();
        $cfg = config("sheets.map.$sheet", []);
        $fields = array_values(array_intersect(self::FIELDS, array_keys($cfg['fields'] ?? [])));
        $numbers = array_values(array_unique(self::serials($record, $fields)));
        if (! $numbers) return collect();

        return ResearchRecord::query()
            ->tap(fn ($q) => self::matchAny($q, self::RESEARCH_FIELDS, $numbers))
            ->orderByDesc('record_date')->orderByDesc('id')->limit(self::LIMIT)->get();
    }

    /** Исполнители записи одной строкой: «Иванов И.И., Петров П.П.». */
    private static function workers(Model $rec): string
    {
        return collect(range(1, 5))->map(fn ($i) => trim((string) $rec->getAttribute("worker$i")))
            ->filter(fn ($v) => $v !== '' && $v !== '—')->unique()->implode(', ');
    }
}
