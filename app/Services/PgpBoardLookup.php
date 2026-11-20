<?php

namespace App\Services;

use App\Models\PgpRecord;
use App\Models\ResearchRecord;

/**
 * Борт уже есть в ПГП (по номеру фюзеляжа) — что делать на новой проверке. Цепочка документов:
 *   1. проверка «Не сдан» → 2. исследование (ремонт) → 3. повторная проверка — НОВАЯ запись,
 *   связанная с ремонтом (after_research_id) → «Сдан» (4) или снова «Не сдан» и новый ремонт.
 * Старую запись не переписываем:
 *  - returned  — вернулся после ремонта: новая запись станет повторной проверкой, данные борта подставляются;
 *  - repair    — числится в ремонте (исследование не на испытаниях или не заведено): запись можно завести,
 *                но с предупреждением; если исследование есть — она станет повторной проверкой к нему;
 *  - delivered — уже сдан: повторно не заводим.
 */
class PgpBoardLookup
{
    /** Данные борта, которые переходят в повторную проверку. */
    public const BOARD_FIELDS = ['board_number', 'board_type', 'ip_address', 'fuselage_number', 'frame_serial', 'cross3_number', 'bracket_serial', 'manet_number'];

    public function __construct(private SectionContext $sections) {}

    /** Номер фюзеляжа для сравнения: без пробелов («S30Z 080213» = «S30Z080213»); регистр не важен — сравнение в MySQL без учёта регистра. */
    public static function key(?string $fuselage): string
    {
        return preg_replace('/\s+/u', '', (string) $fuselage);
    }

    /** Последняя запись ПГП с этим номером фюзеляжа (на текущем участке). */
    public function find(?string $fuselage, ?int $exceptId = null): ?PgpRecord
    {
        $key = self::key($fuselage);
        if ($key === '') return null;
        $model = $this->sections->sheets()['pgp']['model'] ?? PgpRecord::class;

        return $model::query()
            ->whereRaw("REPLACE(TRIM(fuselage_number), ' ', '') = ?", [$key])
            ->when($exceptId, fn ($q) => $q->whereKeyNot($exceptId))
            ->orderByDesc('record_date')->orderByDesc('id')->first();   // последняя по дате проверки, не по порядку ввода
    }

    /**
     * Состояние найденного борта.
     * @return array{record: PgpRecord, state: string, blocked: bool, research: ?ResearchRecord, text: string, url: string, prefill: array}
     */
    public function target(PgpRecord $rec): array
    {
        $date = $rec->record_date?->format('d.m.Y');
        $research = $rec->pendingRecheckResearch();

        if ($research) {
            $state = 'returned';
            $text = "Борт вернулся после ремонта (исследование №{$research->id} по записи №{$rec->id} от {$date}). "
                  . 'Эта запись станет повторной проверкой и свяжется с ремонтом; данные борта подставлены.';
        } elseif ($rec->status === 'Сдан') {
            $state = 'delivered';
            $text = "Борт уже сдан (запись №{$rec->id} от {$date}). Повторная запись не создаётся.";
        } else {
            $state = 'repair';
            $last = $rec->researches()->orderByDesc('id')->first();
            $research = $rec->researches()->whereDoesntHave('recheck')->orderByDesc('id')->first();
            $where = $last
                ? "исследование №{$last->id}: " . mb_strtolower(ResearchRecord::STATUSES[$last->repair_status ?: 'new']['label'] ?? (string) $last->repair_status)
                : 'исследование не заведено';
            $text = "Внимание: борт числится в ремонте (запись №{$rec->id} от {$date}, {$where}). Запись можно сохранить"
                  . ($research ? " — она станет повторной проверкой после исследования №{$research->id}" : '')
                  . '; данные борта подставлены.';
        }

        return [
            'record'   => $rec,
            'state'    => $state,
            'blocked'  => $state === 'delivered',
            'research' => $research,
            'text'     => $text,
            'url'      => route('records.show', ['pgp', $rec->id]),
            'prefill'  => $state !== 'delivered' ? $this->prefill($rec) : [],
        ];
    }

    /** Найти борт по фюзеляжу (кроме записи $exceptId) и описать, что с ним делать. */
    public function check(?string $fuselage, ?int $exceptId = null): ?array
    {
        $rec = $this->find($fuselage, $exceptId);

        return $rec ? $this->target($rec) : null;
    }

    /**
     * Привязать запись к ремонту борта, если пользователь этого не сделал (импорт, правка фюзеляжа, обход формы).
     * Вызывается из PgpRecord::saving: только пока связи нет, прошлая запись борта не «Сдан» и не позже этой.
     */
    public function autoLink(PgpRecord $rec): void
    {
        if ($rec->after_research_id !== null) return;
        if ($rec->exists && ! $rec->isDirty('fuselage_number')) return;

        $prev = $this->find($rec->fuselage_number, $rec->id);
        if (! $prev || $prev->status === 'Сдан') return;
        if ($rec->record_date && $prev->record_date && $prev->record_date->gt($rec->record_date)) return;   // старые данные из таблицы

        $research = $prev->researches()->whereDoesntHave('recheck')->orderByDesc('id')->first();
        if ($research) $rec->after_research_id = $research->id;
    }

    /** Данные борта из прошлой записи — для формы повторной проверки. */
    public function prefill(PgpRecord $rec): array
    {
        return array_filter($rec->only(self::BOARD_FIELDS), fn ($v) => $v !== null && $v !== '');
    }
}
