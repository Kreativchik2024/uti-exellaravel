<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\RepairItem;
use App\Models\ResearchRecord;
use App\Services\RepairCatalog;
use Illuminate\Http\Request;

/**
 * Свои варианты узлов для «Ремонт / Замена» (предложены ремонтниками): администратор подтверждает или отклоняет.
 * Подтверждённый появляется в списке у всех. Отклонённый удаляется; в исследованиях остаётся записанный текст.
 */
class RepairItemController extends Controller
{
    public function index(RepairCatalog $catalog)
    {
        $groups = $catalog->groups();
        $items = RepairItem::with('creator:id,name')->orderBy('is_approved')->orderByDesc('id')->get();
        // В скольких исследованиях выбран (по пунктам ремонта/замены и по сводке у исследований без пунктов)
        $used = \App\Models\ResearchRepairItem::whereIn('item_id', $items->pluck('id'))
            ->selectRaw('item_id, COUNT(DISTINCT research_record_id) AS c')->groupBy('item_id')->pluck('c', 'item_id');
        $legacy = ResearchRecord::whereIn('repair_item_id', $items->pluck('id'))->whereDoesntHave('repairItems')
            ->selectRaw('repair_item_id, COUNT(*) AS c')->groupBy('repair_item_id')->pluck('c', 'repair_item_id');
        foreach ($legacy as $id => $c) $used[$id] = ($used[$id] ?? 0) + $c;

        return view('repair-items.index', [
            'pending'  => $items->where('is_approved', false),
            'approved' => $items->where('is_approved', true),
            'labels'   => $items->mapWithKeys(fn ($i) => [$i->id => $catalog->itemLabel($i, $groups)]),
            'used'     => $used,
        ]);
    }

    public function approve(Request $r, RepairItem $repairItem, RepairCatalog $catalog)
    {
        $data = $r->validate(['name' => ['nullable', 'string', 'max:150']]);
        if (filled($data['name'] ?? null)) $repairItem->name = trim($data['name']);
        $repairItem->forceFill(['is_approved' => true, 'approved_by' => auth()->id(), 'approved_at' => now()])->save();

        // В исследованиях, где выбран этот вариант, — актуальное название
        $label = $catalog->itemLabel($repairItem);
        \App\Models\ResearchRepairItem::where('item_id', $repairItem->id)->update(['label' => $label]);
        ResearchRecord::where('repair_item_id', $repairItem->id)->whereDoesntHave('repairItems')->update(['repair_item' => $label]);
        // Сводка по пунктам — заново, где этот узел среди пунктов
        $ids = \App\Models\ResearchRepairItem::where('item_id', $repairItem->id)->distinct()->pluck('research_record_id');
        foreach (ResearchRecord::with('repairItems')->whereIn('id', $ids)->get() as $rec) {
            $rec->forceFill(['repair_item' => mb_substr($rec->repairItems->pluck('label')->implode('; '), 0, 255)])->saveQuietly();
        }

        AuditLog::write('research', 'repair_item', "Подтверждён узел «{$label}»", ['subject_type' => 'repair_item', 'subject_id' => $repairItem->id, 'section_id' => null]);

        return back()->with('ok', "Подтверждено: {$label}");
    }

    public function reject(RepairItem $repairItem, RepairCatalog $catalog)
    {
        $label = $catalog->itemLabel($repairItem);
        AuditLog::write('research', 'repair_item', "Отклонён узел «{$label}»", ['subject_type' => 'repair_item', 'subject_id' => $repairItem->id, 'section_id' => null]);
        $repairItem->delete();   // в исследованиях остаётся записанный текст, ссылка обнуляется

        return back()->with('ok', "Отклонено: {$label}. В исследованиях, где он выбран, укажите узел из списка");
    }
}
