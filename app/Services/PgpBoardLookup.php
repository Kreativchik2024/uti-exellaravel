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
 *  - repair    — ещё в ремонте: новую запись не заводим, ремонтнику нужно отправить борт на испытания;
 *  - delivered — уже сдан: повторно не заводим.
 */
class PgpBoardLookup
{
    /** Данные борта, которые переходят в повторную проверку. */
    public const BOARD_FIELDS = ['board_number', 'board_type', 'ip_address', 'fuselage_number', 'frame_serial', 'cross3_number', 'bracket_serial', 'manet_number'];

    public function __construct(private SectionContext $sections) {}

    /** Последняя запись ПГП с этим номером фюзеляжа (на текущем участке). */
    public function find(?string $fuselage, ?int $exceptId = null): ?PgpRecord
    {
        $fuselage = trim((string) $fuselage);
        if ($fuselage === '') return null;
        $model = $this->sections->sheets()['pgp']['model'] ?? PgpRecord::class;

        return $model::query()
            ->where('fuselage_number', $fuselage)
            ->when($exceptId, fn ($q) => $q->whereKeyNot($exceptId))
            ->orderByDesc('id')->first();
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
            $where = $last
                ? "исследование №{$last->id}: " . mb_strtolower(ResearchRecord::STATUSES[$last->repair_status ?: 'new']['label'] ?? (string) $last->repair_status)
                : 'исследование не заведено — заведите его на карточке записи';
            $text = "Борт не сдан и числится в ремонте (запись №{$rec->id} от {$date}, {$where}). "
                  . 'Новая запись не создаётся — сначала ремонтник отправляет борт на испытания.';
        }

        return [
            'record'   => $rec,
            'state'    => $state,
            'blocked'  => $state !== 'returned',
            'research' => $research,
            'text'     => $text,
            'url'      => route('records.show', ['pgp', $rec->id]),
            'prefill'  => $state === 'returned' ? $this->prefill($rec) : [],
        ];
    }

    /** Найти борт по фюзеляжу (кроме записи $exceptId) и описать, что с ним делать. */
    public function check(?string $fuselage, ?int $exceptId = null): ?array
    {
        $rec = $this->find($fuselage, $exceptId);

        return $rec ? $this->target($rec) : null;
    }

    /** Данные борта из прошлой записи — для формы повторной проверки. */
    public function prefill(PgpRecord $rec): array
    {
        return array_filter($rec->only(self::BOARD_FIELDS), fn ($v) => $v !== null && $v !== '');
    }
}
