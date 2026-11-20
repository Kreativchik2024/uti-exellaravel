<?php

namespace App\Services;

use App\Models\PgpRecord;
use App\Models\ResearchRecord;
use App\Support\RecordLock;
use Illuminate\Support\Facades\Gate;

/**
 * Борт уже есть в ПГП (по номеру фюзеляжа): на сдаче новая запись не заводится — открывается его запись
 * со всей информацией и журналом. Так борт после ремонта не дублируется, а после «Сдан» уходит
 * из списка «Вернулись после ремонта».
 *
 *  - вернулся после ремонта или его можно править (свой, моложе суток; мастер/админ) — открыть на правку;
 *  - ещё в ремонте — только карточка: ремонтнику нужно отправить его на испытания;
 *  - уже сдан — карточка (перепроверку сданного борта правит мастер).
 */
class PgpBoardLookup
{
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
     * Что делать с найденным бортом.
     * @return array{record: PgpRecord, editable: bool, url: string, state: string, text: string}
     */
    public function target(PgpRecord $rec, bool $editable): array
    {
        $date = $rec->record_date?->format('d.m.Y');
        if ($rec->returnedFromRepair()) {
            $state = 'returned';
            $text = "Борт вернулся после ремонта (запись №{$rec->id} от {$date}). Новая запись не нужна — перепроверьте его в этой записи и поставьте «Сдан».";
        } elseif ($rec->status === 'Сдан') {
            $state = 'delivered';
            $text = "Борт уже сдан (запись №{$rec->id} от {$date}). Повторная запись не создаётся" . ($editable ? ' — при необходимости поправьте эту.' : ' — перепроверку сданного борта оформляет мастер.');
        } else {
            $state = 'repair';
            $research = $rec->researches()->orderByDesc('id')->first();
            $where = $research ? "исследование №{$research->id}: " . mb_strtolower(ResearchRecord::STATUSES[$research->repair_status ?? 'new']['label'] ?? (string) $research->repair_status) : 'исследование не заведено';
            $text = "Борт не сдан и числится в ремонте (запись №{$rec->id} от {$date}, {$where})." . ($editable ? ' Продолжайте в этой записи.' : ' Новая запись не создаётся — ремонтнику нужно отправить его на испытания.');
        }

        return ['record' => $rec, 'editable' => $editable, 'state' => $state, 'text' => $text,
            'url' => $editable ? route('records.edit', ['pgp', $rec->id]) : route('records.show', ['pgp', $rec->id])];
    }

    /** Найти (кроме записи $exceptId) и решить для текущего пользователя — с учётом прав и суточной блокировки. */
    public function forUser(?string $fuselage, ?int $exceptId = null): ?array
    {
        $rec = $this->find($fuselage, $exceptId);
        if (! $rec) return null;

        return $this->target($rec, Gate::allows('update', $rec) && ! RecordLock::isLocked($rec));
    }
}
