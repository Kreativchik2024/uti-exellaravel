<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

/**
 * Работа с записью после создания: исследования (статус, ремонт / замена, акт),
 * комментарии и пометка технолога ПГП. Создание и правка записей — RecordController.
 */
class RecordReviewController extends Controller
{
    use Concerns\RecordSheets;

    /**
     * Статус исследования кнопками на карточке: не обработан / в работе / отправлен в разбор / отправлен в конвейер.
     * Ставят ремонтник, мастер и админ. «В конвейер» = отремонтировано: брак ПГП уходит из списка ремонтника.
     */
    public function researchStatus(Request $r, string $sheet, int $id)
    {
        abort_unless($sheet === 'research', 404);
        $record = $this->cfg($sheet)['model']::findOrFail($id);
        $statuses = \App\Models\ResearchRecord::STATUSES;

        $data = $r->validate(['repair_status' => ['required', \Illuminate\Validation\Rule::in(array_keys($statuses))]]);
        $old = $record->repair_status ?: 'new';

        if ($old !== $data['repair_status']) {
            $record->forceFill(['repair_status' => $data['repair_status'], 'updated_by' => auth()->id()])->save();
            \App\Models\AuditLog::write('research', 'status', "Исследование #{$record->id}: {$statuses[$old]['label']} → {$statuses[$data['repair_status']]['label']}",
                ['subject_type' => 'record', 'subject_id' => $record->id, 'section_id' => null]);
        }

        return back()->with('ok', 'Статус: ' . $statuses[$data['repair_status']]['label']);
    }

    /**
     * Панель «Ремонт» исследования (ремонтник, мастер, админ): ремонт или замена и какой узел —
     * блок из дерева справочника кодов, подтверждённый свой вариант или новый (уходит на проверку администратору).
     */
    public function researchRepair(Request $r, string $sheet, int $id)
    {
        abort_unless($sheet === 'research', 404);
        $record = $this->cfg($sheet)['model']::findOrFail($id);

        $data = $r->validate([
            'repair_action' => ['required', \Illuminate\Validation\Rule::in(array_keys(\App\Models\ResearchRecord::REPAIR_ACTIONS))],
            'repair_node'   => ['nullable', 'string', 'max:20'],
            'custom_name'   => ['nullable', 'string', 'max:150'],
            'custom_group'  => ['nullable', 'integer'],
        ], [], ['repair_action' => 'Ремонт или замена', 'custom_name' => 'Свой вариант']);

        $picked = app(\App\Services\RepairCatalog::class)->resolve(
            $data['repair_node'] ?? null, $data['custom_name'] ?? null, isset($data['custom_group']) ? (int) $data['custom_group'] : null,
            $record, (int) auth()->id());

        $actions = \App\Models\ResearchRecord::REPAIR_ACTIONS;
        $record->forceFill([
            'repair_action'   => $data['repair_action'],
            'repair_item'     => $picked['label'],
            'repair_group_id' => $picked['group_id'],
            'repair_item_id'  => $picked['item_id'],
            'updated_by'      => auth()->id(),
        ])->save();

        \App\Models\AuditLog::write('research', 'repair', "Исследование #{$record->id}: {$actions[$data['repair_action']]} — {$picked['label']}" . ($picked['pending'] ? ' (свой вариант, на проверке)' : ''),
            ['subject_type' => 'record', 'subject_id' => $record->id, 'section_id' => null]);

        $msg = "{$actions[$data['repair_action']]}: {$picked['label']}";
        if ($picked['pending']) $msg .= ' — свой вариант отправлен на проверку администратору';
        if ($record->needsAct()) $msg .= '. Технологу (ОТК) нужно оформить акт';
        elseif ($record->repair_action === 'repair') $msg .= '. После «Отправлен в конвейер» борт уйдёт технологу на приёмку';

        return back()->with('ok', $msg);
    }

    /**
     * Технолог (ОТК), мастер или админ отмечает проверку: при замене — «Акт оформлен»,
     * при ремонте, отправленном в конвейер, — «Принято технологом». Исследование уходит из списка технолога.
     */
    public function researchAct(Request $r, string $sheet, int $id)
    {
        abort_unless($sheet === 'research', 404);
        $record = $this->cfg($sheet)['model']::findOrFail($id);
        $kind = $record->techCheckKind();
        abort_unless($kind !== null, 422, 'Технологу отмечать нечего: нет замены или ремонт ещё не отправлен в конвейер');

        $what = $kind === 'act' ? 'акт на замену оформлен' : 'ремонт принят технологом';
        if (! $record->act_done_at) {
            $record->forceFill(['act_done_at' => now(), 'act_done_by' => auth()->id()])->save();
            \App\Models\AuditLog::write('research', 'act', "Исследование #{$record->id}: {$what} ({$record->repair_item})",
                ['subject_type' => 'record', 'subject_id' => $record->id, 'section_id' => null]);
        }

        return back()->with('ok', $kind === 'act' ? 'Акт на замену отмечен как оформленный' : 'Ремонт принят технологом');
    }

    /** Комментарий к исследованию. Комментарии копятся — видна вся история по детали. */
    public function comment(Request $r, string $sheet, int $id)
    {
        abort_unless($sheet === 'research', 404);
        $record = $this->cfg($sheet)['model']::findOrFail($id);

        $data = $r->validate(['body' => ['required', 'string', 'max:2000']], [], ['body' => 'Комментарий']);

        $comment = $record->comments()->make(['body' => trim($data['body'])]);
        $comment->user_id = auth()->id();
        $comment->save();

        return redirect()->to(route('records.show', [$sheet, $record->id]) . '#comments')->with('ok', 'Комментарий добавлен');
    }

    /** Пометка технолога к записи ПГП: текст и отметка «обработан». Пишет только группа ОТК (маршрут role:otk). */
    public function tech(Request $r, string $sheet, int $id)
    {
        abort_unless($sheet === 'pgp', 404);
        $cfg    = $this->cfg($sheet);
        $record = $cfg['model']::findOrFail($id);

        $data = $r->validate([
            'tech_note'      => ['nullable', 'string', 'max:2000'],
            'tech_processed' => ['sometimes', 'boolean'],
        ], [], ['tech_note' => 'Пометка технолога']);

        $changes = [];
        if (array_key_exists('tech_note', $data) && $data['tech_note'] !== $record->tech_note) $changes['пометка'] = 'изменена';
        if (array_key_exists('tech_processed', $data) && (bool) $data['tech_processed'] !== (bool) $record->tech_processed) {
            $changes['обработан'] = $data['tech_processed'] ? 'да' : 'нет';
        }

        $record->forceFill($data + ['tech_updated_by' => auth()->id(), 'tech_updated_at' => now()])->save();

        if ($changes) {
            \App\Models\AuditLog::write('pgp', 'tech', "Пометка технолога к записи ПГП #{$record->id}: " . implode(', ', array_map(fn ($k, $v) => "$k — $v", array_keys($changes), $changes)),
                ['subject_type' => 'record', 'subject_id' => $record->id, 'section_id' => null]);
        }

        return back()->with('ok', 'Пометка технолога сохранена');
    }
}
