<?php

namespace App\Services;

use App\Models\FaultCode;
use App\Models\FaultCodeArchive;
use App\Models\FaultCodeGroup;

/**
 * Рейтинг кодов неисправностей, привязанный к справочнику. Каждая запись с кодом попадает в одну строку:
 *  - код совпал с кодом справочника (без учёта регистра) — это он;
 *  - кода уже нет в справочнике, но он есть в архиве стёртого справочника — это он, с описанием из архива;
 *  - вписан текст вместо кода и он похож на описание ровно одного кода справочника — это тот код;
 *  - остальное группируется по похожести текста: разные написания одной неисправности — одна строка.
 */
class FaultCodeRating
{
    public function __construct(private SectionContext $sections) {}

    /**
     * @param array<int, array{code: string, sheet: string, sheet_title?: string, count: int, first_date?: ?string, last_date?: ?string}> $rows
     *        строки «код × вкладка» (группировка по коду делается в БД)
     * @return array<int, array{key: string, code: ?string, text: string, description: string, group: ?string, known: bool,
     *                          count: int, sheets: array<string, string>, first_date: ?string, last_date: ?string, variants: array<string, int>}>
     */
    public function rate(array $rows): array
    {
        $catalog = FaultCode::query()->forSection($this->sections->current())->get();
        FaultCodeGroup::attachTo($catalog);
        $archive = FaultCodeArchive::all()->keyBy(fn ($a) => mb_strtolower($a->code));

        $clusters = [];   // неизвестные тексты: id => первое написание
        $agg = [];

        foreach ($rows as $row) {
            $raw = trim(preg_replace('/\s+/u', ' ', (string) $row['code']));
            if ($raw === '' || $raw === '-' || $raw === '—') continue;
            $lower = mb_strtolower($raw);

            if ($c = $this->canonicalCode($raw, $catalog)) {
                $meta = ['key' => 'c:' . $c->code, 'code' => $c->code, 'description' => (string) $c->description, 'group' => $c->group?->label(), 'known' => true];
            } elseif ($a = $archive[$lower] ?? null) {
                $meta = ['key' => 'c:' . $a->code, 'code' => $a->code, 'description' => (string) $a->description, 'group' => $a->group_label, 'known' => false];
            } else {
                $id = $this->clusterOf($raw, $clusters);
                $meta = ['key' => 'u:' . $id, 'code' => null, 'description' => '', 'group' => null, 'known' => false];
            }

            $e = $agg[$meta['key']] ??= $meta + ['count' => 0, 'sheets' => [], 'first_date' => null, 'last_date' => null, 'variants' => []];
            $e['count'] += (int) $row['count'];
            $e['sheets'][(string) $row['sheet']] = (string) ($row['sheet_title'] ?? $row['sheet']);
            $e['variants'][$raw] = ($e['variants'][$raw] ?? 0) + (int) $row['count'];
            $first = $row['first_date'] ?? null;
            $last = $row['last_date'] ?? null;
            if ($first && (! $e['first_date'] || $first < $e['first_date'])) $e['first_date'] = $first;
            if ($last && (! $e['last_date'] || $last > $e['last_date'])) $e['last_date'] = $last;
            $agg[$meta['key']] = $e;
        }

        $out = [];
        foreach ($agg as $e) {
            arsort($e['variants']);
            // Текст строки: код и его описание, а для неизвестных — самое частое написание
            $e['text'] = $e['code']
                ? $e['code'] . ($e['description'] !== '' ? ' — ' . $e['description'] : '')
                : (string) array_key_first($e['variants']);
            $out[] = $e;
        }
        usort($out, fn ($a, $b) => $b['count'] <=> $a['count']);

        return $out;
    }

    /**
     * Код справочника для написания из записей: совпал код (без учёта регистра) или текст однозначно похож
     * на описание одного кода. Иначе null. Используется и рейтингом, и командой привязки записей.
     */
    public function canonicalCode(string $raw, $catalog): ?FaultCode
    {
        $raw = trim(preg_replace('/\s+/u', ' ', $raw));
        if ($raw === '') return null;

        $exact = $catalog->first(fn ($c) => mb_strtolower($c->code) === mb_strtolower($raw));

        return $exact ?? $this->matchDescription($raw, $catalog);
    }

    /** Текст похож на описание ровно одного кода справочника (несколько равных — не угадываем). */
    private function matchDescription(string $raw, $catalog): ?FaultCode
    {
        if (! FaultCodes::looksLikeDescription($raw)) return null;

        $scored = $catalog->map(fn ($c) => [$c, FaultCodes::similarity($raw, (string) $c->description)])
            ->filter(fn ($x) => $x[1] >= FaultCodes::SAME)->sortByDesc(fn ($x) => $x[1])->values();
        if ($scored->isEmpty()) return null;
        if ($scored->count() > 1 && $scored[1][1] >= $scored[0][1] - 0.001) return null;

        return $scored[0][0];
    }

    /** Номер группы похожих неизвестных написаний. */
    private function clusterOf(string $raw, array &$clusters): int
    {
        foreach ($clusters as $id => $rep) {
            if (mb_strtolower($rep) === mb_strtolower($raw) || FaultCodes::similarity($rep, $raw) >= FaultCodes::SAME) return $id;
        }
        $clusters[] = $raw;

        return array_key_last($clusters);
    }
}
