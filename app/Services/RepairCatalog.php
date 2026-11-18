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
    public function __construct(private SectionContext $sections) {}

    /** Блоки справочника кодов (общие и текущего участка), ключи — id, с цепочкой родителей. */
    public function groups(): Collection
    {
        $section = $this->sections->current();

        return FaultCodeGroup::tree()
            ->filter(fn ($g) => $g->section_id === null || ($section && (int) $g->section_id === (int) $section->id))
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
            ->where(fn ($q) => $q->where('is_approved', true)->orWhere('id', $research?->repair_item_id ?? 0))
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
        ];
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
            if ($item && ($item->is_approved || $item->id === $research->repair_item_id) && ($item->group_id === null || $groups->has($item->group_id))) {
                return ['label' => $this->itemLabel($item, $groups), 'group_id' => $item->group_id, 'item_id' => $item->id, 'pending' => ! $item->is_approved];
            }
        }

        throw ValidationException::withMessages(['repair_node' => 'Выберите узел из списка или предложите свой вариант.']);
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
