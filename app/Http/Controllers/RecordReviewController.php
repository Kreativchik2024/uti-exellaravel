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
            $this->rememberUndo($record, 'status', ['repair_status' => $old], 'Статус: ' . $statuses[$data['repair_status']]['label']);
        }

        return back()->with('ok', 'Статус: ' . $statuses[$data['repair_status']]['label']);
    }

    /** Сколько секунд можно отменить последнее сохранение. */
    public const UNDO_SECONDS = 120;

    /** Запомнить, как было до сохранения, — для кнопки «Отменить» во всплывающем сообщении. */
    private function rememberUndo($record, string $type, array $before, string $message): void
    {
        session()->put("undo_store.research.{$record->id}", ['type' => $type, 'before' => $before, 'user' => auth()->id(), 'at' => time()]);
        session()->flash('undo', ['message' => $message, 'url' => route('records.researchUndo', ['research', $record->id]), 'seconds' => self::UNDO_SECONDS]);
    }

    /** «Отменить»: вернуть ремонт/замену или статус, как было до последнего сохранения (тот же пользователь, 2 минуты). */
    public function researchUndo(Request $r, string $sheet, int $id)
    {
        abort_unless($sheet === 'research', 404);
        $record = $this->cfg($sheet)['model']::findOrFail($id);
        $undo = session()->pull("undo_store.research.{$record->id}");
        if (! $undo || $undo['user'] !== auth()->id() || time() - $undo['at'] > self::UNDO_SECONDS) {
            return back()->with('error', 'Отменить уже нельзя — прошло больше двух минут или сохранение было не ваше');
        }

        $statuses = \App\Models\ResearchRecord::STATUSES;
        if ($undo['type'] === 'status') {
            $from = $record->repair_status ?: 'new';
            $to = $undo['before']['repair_status'];
            $record->forceFill(['repair_status' => $to, 'updated_by' => auth()->id()])->save();
            \App\Models\AuditLog::write('research', 'status', "Исследование #{$record->id}: {$statuses[$from]['label']} → {$statuses[$to]['label']} (отмена)",
                ['subject_type' => 'record', 'subject_id' => $record->id, 'section_id' => null]);

            return back()->with('ok', 'Отменено — статус снова «' . $statuses[$to]['label'] . '»');
        }

        // Ремонт/замена: пункты и сводка — как были
        $b = $undo['before'];
        \Illuminate\Support\Facades\DB::transaction(function () use ($record, $b) {
            $record->repairItems()->delete();
            foreach ($b['items'] as $pos => $it) $record->repairItems()->create($it + ['position' => $pos]);
            $record->forceFill($b['summary'] + ['updated_by' => auth()->id()])->save();
        });
        \App\Models\AuditLog::write('research', 'repair', "Исследование #{$record->id}: " . ($b['items']
                ? implode('; ', array_map(fn ($it) => (\App\Models\ResearchRecord::REPAIR_ACTIONS[$it['action']] ?? $it['action']) . ' — ' . $it['label'], $b['items']))
                : 'ремонт и замена убраны') . ' (отмена)',
            ['subject_type' => 'record', 'subject_id' => $record->id, 'section_id' => null]);

        return back()->with('ok', 'Отменено — ремонт и замена как были');
    }

    /**
     * Панель «Ремонт» исследования (ремонтник, мастер, админ): один или несколько пунктов «ремонт / замена + узел».
     * Узел — блок из дерева справочника кодов, подтверждённый свой вариант или новый (уходит на проверку администратору).
     * Пункты заменяются целиком; repair_action / repair_item исследования — сводка: есть хоть одна замена — «замена» (нужен акт).
     */
    public function researchRepair(Request $r, string $sheet, int $id)
    {
        abort_unless($sheet === 'research', 404);
        $record = $this->cfg($sheet)['model']::findOrFail($id);
        $actions = \App\Models\ResearchRecord::REPAIR_ACTIONS;

        // Старый формат (одно поле) — как один пункт
        if (! $r->has('items') && $r->has('repair_action')) {
            $r->merge(['items' => [$r->only(['repair_action', 'repair_node', 'custom_name', 'custom_group'])]]);
        }

        $data = $r->validate([
            'items'                 => ['required', 'array', 'min:1', 'max:20'],
            'items.*.repair_action' => ['required', \Illuminate\Validation\Rule::in(array_keys($actions))],
            'items.*.repair_node'   => ['nullable', 'string', 'max:20'],
            'items.*.custom_name'   => ['nullable', 'string', 'max:150'],
            'items.*.custom_group'  => ['nullable', 'integer'],
        ], [], ['items' => 'Пункты ремонта', 'items.*.repair_action' => 'Ремонт или замена', 'items.*.custom_name' => 'Свой вариант']);

        $catalog = app(\App\Services\RepairCatalog::class);
        $picked = [];
        foreach (array_values($data['items']) as $n => $it) {
            try {
                $p = $catalog->resolve($it['repair_node'] ?? null, $it['custom_name'] ?? null,
                    isset($it['custom_group']) ? (int) $it['custom_group'] : null, $record, (int) auth()->id());
            } catch (\Illuminate\Validation\ValidationException $e) {
                throw \Illuminate\Validation\ValidationException::withMessages(['repair_node' => 'Пункт ' . ($n + 1) . ': выберите узел из списка или предложите свой вариант.']);
            }
            $key = $it['repair_action'] . '|' . mb_strtolower($p['label']);
            $picked[$key] ??= $p + ['action' => $it['repair_action']];   // одинаковые пункты — один раз
        }
        $picked = array_values($picked);

        // Как было — для «Отменить»
        $before = [
            'items'   => $record->repairItems()->get()->map(fn ($it) => $it->only(['action', 'label', 'group_id', 'item_id']))->all(),
            'summary' => $record->only(['repair_action', 'repair_item', 'repair_group_id', 'repair_item_id']),
        ];

        $first = $picked[0];
        \Illuminate\Support\Facades\DB::transaction(function () use ($record, $picked, $first) {
            $record->repairItems()->delete();
            foreach ($picked as $pos => $p) {
                $record->repairItems()->create(['position' => $pos, 'action' => $p['action'], 'label' => $p['label'],
                    'group_id' => $p['group_id'], 'item_id' => $p['item_id']]);
            }
            $record->forceFill([
                'repair_action'   => collect($picked)->contains('action', 'replacement') ? 'replacement' : 'repair',
                'repair_item'     => mb_substr(implode('; ', array_column($picked, 'label')), 0, 255),
                'repair_group_id' => $first['group_id'],
                'repair_item_id'  => $first['item_id'],
                'updated_by'      => auth()->id(),
            ])->save();
        });

        $list = implode('; ', array_map(fn ($p) => $actions[$p['action']] . ' — ' . $p['label'] . ($p['pending'] ? ' (свой вариант, на проверке)' : ''), $picked));
        \App\Models\AuditLog::write('research', 'repair', "Исследование #{$record->id}: {$list}",
            ['subject_type' => 'record', 'subject_id' => $record->id, 'section_id' => null]);

        $msg = count($picked) === 1 ? "{$actions[$first['action']]}: {$first['label']}" : 'Сохранено пунктов: ' . count($picked);
        if (collect($picked)->contains('pending', true)) $msg .= ' — свой вариант отправлен на проверку администратору';
        if ($record->needsAct()) $msg .= '. Технологу нужно оформить акт';
        elseif ($record->repair_action === 'repair') $msg .= '. После «Отправлен на испытания» борт уйдёт технологу на приёмку';

        $this->rememberUndo($record, 'repair', $before, count($picked) === 1 ? "{$actions[$first['action']]}: {$first['label']}" : 'Сохранено пунктов: ' . count($picked));

        return back()->with('ok', $msg);
    }

    /**
     * Технолог, мастер или админ отмечает проверку: при замене — «Акт оформлен»,
     * при ремонте, отправленном в конвейер, — «Принято технологом». Исследование уходит из списка технолога.
     */
    public function researchAct(Request $r, string $sheet, int $id)
    {
        abort_unless($sheet === 'research', 404);
        $record = $this->cfg($sheet)['model']::findOrFail($id);
        $kind = $record->techCheckKind();
        abort_unless($kind !== null, 422, 'Технологу отмечать нечего: нет замены или ремонт ещё не отправлен на испытания');

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

    /** Пометка технолога к записи ПГП: текст и отметка «обработан». Пишет только группа «Технолог» (маршрут role:technologist). */
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
