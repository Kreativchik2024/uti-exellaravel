<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\FaultCodeGroup;
use App\Models\RepairItem;
use App\Models\ResearchRecord;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Узлы для «Ремонт / Замена» в исследовании:
 *  - дерево блоков — то же, что в справочнике кодов неисправностей (общие и текущего участка);
 *  - свои варианты (RepairItem) — предлагает ремонтник, подтверждает администратор.
 *    Предложенный вариант сразу записывается в исследование с пометкой «на проверке».
 */
class RepairCatalog
{
    /**
     * Блоки справочника кодов, которых нет в выборе «Ремонт / Замена» (вместе со всем, что в них вложено).
     * В справочнике кодов неисправностей они остаются. Сравнение без учёта регистра.
     */
    public const EXCLUDED_BLOCKS = ['ВМГ', 'ВМР', 'КОММУТАЦИЯ', 'ИССЛЕДОВАНИЯ'];

    public function __construct(private SectionContext $sections) {}

    /** Блоки справочника кодов (общие и текущего участка) без исключённых, ключи — id, с цепочкой родителей. */
    public function groups(): Collection
    {
        $section = $this->sections->current();
        $excluded = array_map('mb_strtoupper', self::EXCLUDED_BLOCKS);

        return FaultCodeGroup::tree()
            ->filter(fn ($g) => $g->section_id === null || ($section && (int) $g->section_id === (int) $section->id))
            ->reject(fn ($g) => array_intersect(array_map(fn ($p) => mb_strtoupper(trim($p->name)), $g->path()), $excluded))
            ->sortBy(fn ($g) => $g->sortKey());
    }

    /**
     * Данные для выбора: блоки и подтверждённые свои варианты (плюс неподтверждённый, уже выбранный в этом исследовании).
     * @return array{groups: list<array{id:int,name:string,parent:?int}>, items: list<array{id:int,name:string,group:?int,approved:bool}>}
     */
    public function pickerData(?ResearchRecord $research = null): array
    {
        $groups = $this->groups();
        $items = RepairItem::query()
            ->where(fn ($q) => $q->where('is_approved', true)->orWhereIn('id', $this->usedItemIds($research) ?: [0]))
            ->orderBy('name')->get()
            ->filter(fn ($i) => $i->group_id === null || $groups->has($i->group_id));

        return [
            'groups' => $groups->map(fn ($g) => [
                'id' => $g->id, 'name' => $g->name,
                'parent' => $g->parent_id && $groups->has($g->parent_id) ? (int) $g->parent_id : null,
            ])->values()->all(),
            'items' => $items->map(fn ($i) => [
                'id' => $i->id, 'name' => $i->name, 'group' => $i->group_id ? (int) $i->group_id : null, 'approved' => (bool) $i->is_approved,
            ])->values()->all(),
            // Частые — общие и отдельно для ремонта и замены (участок учтён: у участка свои исследования и блоки)
            'frequent' => [
                'all'         => $this->frequent($groups, $items, null),
                'repair'      => $this->frequent($groups, $items, 'repair'),
                'replacement' => $this->frequent($groups, $items, 'replacement'),
            ],
            'suggested' => $research ? $this->suggested($research, $groups, $items) : null,
            'previous'  => $research ? $this->previous($research, $groups, $items) : null,
        ];
    }

    /** «g12» / «i5» для пункта, если узел есть в выборе; иначе null. */
    private function keyOf(?int $groupId, ?int $itemId, Collection $groups, Collection $items): ?string
    {
        if ($itemId) return $items->contains('id', $itemId) ? 'i' . $itemId : null;

        return $groupId && $groups->has($groupId) ? 'g' . $groupId : null;
    }

    /** Самые частые узлы из запроса пунктов (до $limit). */
    private function topKeys($query, Collection $groups, Collection $items, int $limit): array
    {
        return $query->selectRaw('group_id, item_id, COUNT(*) AS c')->groupBy('group_id', 'item_id')
            ->orderByDesc('c')->limit(40)->get()
            ->map(fn ($r) => $this->keyOf($r->group_id ? (int) $r->group_id : null, $r->item_id ? (int) $r->item_id : null, $groups, $items->where('is_approved', true)))
            ->filter()->unique()->take($limit)->values()->all();
    }

    /** Узлы, которые выбирают чаще всего (до 6); $action — только для ремонта или только для замены. */
    private function frequent(Collection $groups, Collection $items, ?string $action): array
    {
        $q = \App\Models\ResearchRepairItem::query();
        if ($action) $q->where('action', $action);

        return $this->topKeys($q, $groups, $items, 6);
    }

    /**
     * Подсказка по отказу: какие узлы указывали в других исследованиях с тем же кодом неисправности
     * или тем же заявленным отказом. Учится на уже внесённых ремонтах — справочник соответствий не нужен.
     * @return array{for: string, keys: list<string>}|null
     */
    private function suggested(ResearchRecord $research, Collection $groups, Collection $items): ?array
    {
        $code = trim((string) $research->fault_code);
        $defect = mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', (string) $research->reported_defect)));
        if ($code === '' && $defect === '') return null;

        $norm = fn ($v) => mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', (string) $v)));
        $ids = $code !== '' ? ResearchRecord::query()->whereKeyNot($research->id)->where('fault_code', $code)->pluck('id')->all() : [];
        if ($defect !== '') {
            // Сравнение без учёта регистра и пробелов — в PHP (LOWER в SQLite не понимает кириллицу); последние 3000 исследований
            $same = ResearchRecord::query()->whereKeyNot($research->id)->whereNotNull('reported_defect')->where('reported_defect', '!=', '')
                ->whereNotNull('repair_action')->orderByDesc('id')->limit(3000)->get(['id', 'reported_defect'])
                ->filter(fn ($r) => $norm($r->reported_defect) === $defect)->pluck('id')->all();
            $ids = array_merge($ids, $same);
        }
        if (! $ids) return null;
        $keys = $this->topKeys(\App\Models\ResearchRepairItem::query()->whereIn('research_record_id', $ids), $groups, $items, 4);

        return $keys ? ['for' => $code !== '' ? 'коду ' . $code : '«' . mb_strimwidth(trim((string) $research->reported_defect), 0, 40, '…') . '»', 'keys' => $keys] : null;
    }

    /**
     * «Как в прошлый раз»: пункты последнего другого исследования той же детали (тот же фюзеляж, моторама или кронштейн).
     * @return array{id: int, date: ?string, rows: list<array{action: string, node: string, label: string, pending: bool}>}|null
     */
    private function previous(ResearchRecord $research, Collection $groups, Collection $items): ?array
    {
        $prev = $research->samePartHistory()
            ->reject(fn ($r) => $r->id === $research->id || ! $r->repair_action)
            ->last();
        if (! $prev) return null;

        $src = $prev->repairItems()->get();
        $rows = $src->isNotEmpty()
            ? $src->map(fn ($it) => [$it->action, $it->group_id, $it->item_id, $it->label])
            : collect([[$prev->repair_action, $prev->repair_group_id, $prev->repair_item_id, $prev->repair_item]]);

        $rows = $rows->map(function ($r) use ($groups, $items) {
            [$action, $g, $i, $label] = $r;
            $key = $this->keyOf($g ? (int) $g : null, $i ? (int) $i : null, $groups, $items->where('is_approved', true));

            return $key ? ['action' => $action, 'node' => $key, 'label' => (string) $label, 'pending' => false] : null;
        })->filter()->values()->all();

        return $rows ? ['id' => $prev->id, 'date' => $prev->record_date?->format('d.m.Y'), 'rows' => $rows] : null;
    }

    /**
     * Выбор → что записать в исследование. $node — «g12» (блок) или «i5» (свой вариант);
     * $customName — новый вариант (в блоке $customGroup), уходит на проверку администратору.
     * @return array{label: string, group_id: ?int, item_id: ?int, pending: bool}
     */
    public function resolve(?string $node, ?string $customName, ?int $customGroup, ResearchRecord $research, int $userId): array
    {
        $groups = $this->groups();
        $customName = trim((string) preg_replace('/\s+/u', ' ', (string) $customName));

        if ($customName !== '') {
            $group = $customGroup && $groups->has($customGroup) ? $groups[$customGroup] : null;
            // Такой вариант уже есть (подтверждён или ждёт проверки) — не дублируем
            $item = RepairItem::query()->where('group_id', $group?->id)
                ->get()->first(fn ($i) => mb_strtolower($i->name) === mb_strtolower($customName));
            if (! $item) {
                $item = new RepairItem(['name' => mb_strtoupper(mb_substr($customName, 0, 1)) . mb_substr($customName, 1, 149), 'group_id' => $group?->id]);
                $item->created_by = $userId;
                $item->save();
                AuditLog::write('research', 'repair_item', "Предложен узел «{$this->itemLabel($item, $groups)}» (исследование #{$research->id}) — ждёт проверки администратора",
                    ['subject_type' => 'repair_item', 'subject_id' => $item->id, 'section_id' => null]);
            }

            return ['label' => $this->itemLabel($item, $groups), 'group_id' => $group?->id, 'item_id' => $item->id, 'pending' => ! $item->is_approved];
        }

        if (preg_match('/^g(\d+)$/', (string) $node, $m) && $groups->has((int) $m[1])) {
            $g = $groups[(int) $m[1]];

            return ['label' => $g->label(), 'group_id' => $g->id, 'item_id' => null, 'pending' => false];
        }

        if (preg_match('/^i(\d+)$/', (string) $node, $m)) {
            $item = RepairItem::find((int) $m[1]);
            // неподтверждённый — только тот, что уже выбран в этом исследовании
            if ($item && ($item->is_approved || in_array($item->id, $this->usedItemIds($research), true)) && ($item->group_id === null || $groups->has($item->group_id))) {
                return ['label' => $this->itemLabel($item, $groups), 'group_id' => $item->group_id, 'item_id' => $item->id, 'pending' => ! $item->is_approved];
            }
        }

        throw ValidationException::withMessages(['repair_node' => 'Выберите узел из списка или предложите свой вариант.']);
    }

    /** Свои варианты, уже выбранные в исследовании (в любом пункте) — их можно оставить, даже если не подтверждены. */
    private function usedItemIds(?ResearchRecord $research): array
    {
        if (! $research?->exists) return [];

        return array_values(array_unique(array_map('intval', array_filter(array_merge(
            [$research->repair_item_id], $research->repairItems()->pluck('item_id')->all()
        )))));
    }

    /** «МОТОРАМА · Кросс 3 · Разъём питания» */
    public function itemLabel(RepairItem $item, ?Collection $groups = null): string
    {
        $groups ??= FaultCodeGroup::tree();
        $g = $item->group_id ? ($groups[$item->group_id] ?? null) : null;

        return ($g ? $g->label() . ' · ' : '') . $item->name;
    }

    public function pendingCount(): int
    {
        return RepairItem::where('is_approved', false)->count();
    }
}
