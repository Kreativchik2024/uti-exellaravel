<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\FieldMask;
use App\Models\FieldMaskAssignment;
use App\Models\Setting;
use App\Services\FieldMasks;
use App\Support\InputMask;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Админ → Маски полей: библиотека масок (создать, изменить, удалить) и маски у каждого поля вкладки ПГП.
 * Маска: 9 — цифра, A — буква, * — буква или цифра, остальное — постоянные символы (App\Support\InputMask).
 */
class FieldMaskController extends Controller
{
    private function sheetCfg(string $sheet): array
    {
        abort_unless(in_array($sheet, FieldMasks::SHEETS, true), 404);

        return config("sheets.map.$sheet");
    }

    public function index(Request $r, FieldMasks $svc)
    {
        $sheet = $r->query('sheet', FieldMasks::SHEETS[0]);
        $cfg = $this->sheetCfg($sheet);
        $assigned = $svc->forSheet($sheet);

        $fields = [];
        foreach ($cfg['fields'] as $name => $f) {
            $kind = FieldMasks::kind($name, $f);
            $fields[] = [
                'name'   => $name,
                'label'  => $f['label'] ?? $name,
                'type'   => $f['type'] ?? 'text',
                'kind'   => $kind,
                'masks'  => array_map(fn ($m) => $m->id, $assigned[$name] ?? []),
                'prefix' => $kind === 'prefix' ? $svc->prefix($sheet, $name, (string) ($f['prefix'] ?? '')) : null,
                'defaultPrefix' => $f['prefix'] ?? null,
            ];
        }

        $masks = FieldMask::query()->withCount('assignments')->orderBy('name')->get();

        return view('field-masks.index', compact('sheet', 'cfg', 'fields', 'masks'));
    }

    private function validMask(Request $r): array
    {
        $data = $r->validate([
            'name'    => ['required', 'string', 'max:100'],
            'pattern' => ['required', 'string', 'max:120'],
            'hint'    => ['nullable', 'string', 'max:255'],
        ], [], ['name' => 'Название', 'pattern' => 'Маска', 'hint' => 'Пояснение']);
        if ($err = InputMask::error($data['pattern'])) throw ValidationException::withMessages(['pattern' => $err]);

        return $data;
    }

    public function store(Request $r)
    {
        $mask = FieldMask::create($this->validMask($r));
        AuditLog::write('field_masks', 'created', "Создана маска «{$mask->name}»: {$mask->pattern}", ['subject_type' => 'field_mask', 'subject_id' => $mask->id, 'section_id' => null]);

        // Сразу привязать к полю, если маску создавали из строки поля
        if ($field = $r->input('attach_field')) {
            $sheet = (string) $r->input('sheet', FieldMasks::SHEETS[0]);
            $cfg = $this->sheetCfg($sheet);
            if (isset($cfg['fields'][$field]) && FieldMasks::kind($field, $cfg['fields'][$field]) === 'mask') {
                FieldMaskAssignment::firstOrCreate(['sheet' => $sheet, 'field' => $field, 'field_mask_id' => $mask->id],
                    ['position' => FieldMaskAssignment::where('sheet', $sheet)->where('field', $field)->count()]);
            }
        }

        return back()->with('ok', "Маска «{$mask->name}» создана");
    }

    public function update(Request $r, FieldMask $fieldMask)
    {
        $old = $fieldMask->pattern;
        $fieldMask->update($this->validMask($r));
        AuditLog::write('field_masks', 'updated', "Изменена маска «{$fieldMask->name}»: {$old} → {$fieldMask->pattern}", ['subject_type' => 'field_mask', 'subject_id' => $fieldMask->id, 'section_id' => null]);

        return back()->with('ok', "Маска «{$fieldMask->name}» сохранена");
    }

    public function destroy(FieldMask $fieldMask)
    {
        AuditLog::write('field_masks', 'deleted', "Удалена маска «{$fieldMask->name}»: {$fieldMask->pattern}", ['subject_type' => 'field_mask', 'subject_id' => $fieldMask->id, 'section_id' => null]);
        $fieldMask->delete();   // привязки к полям удаляются вместе с ней

        return back()->with('ok', 'Маска удалена — поля, где она была, больше её не проверяют');
    }

    /** Маски одного поля (по порядку) или префикс IP. */
    public function saveField(Request $r, string $sheet, string $field)
    {
        $cfg = $this->sheetCfg($sheet);
        abort_unless(isset($cfg['fields'][$field]), 404);
        $f = $cfg['fields'][$field];
        $label = $f['label'] ?? $field;

        if (FieldMasks::kind($field, $f) === 'prefix') {
            $data = $r->validate(['prefix' => ['nullable', 'string', 'max:20', 'regex:/^(\d{1,3}\.){1,3}$/']], [
                'prefix.regex' => 'Постоянная часть адреса — числа с точками и точка в конце, например 192.168.1.',
            ]);
            $prefix = trim((string) ($data['prefix'] ?? ''));
            Setting::set(FieldMasks::prefixKey($sheet, $field), $prefix === '' || $prefix === ($f['prefix'] ?? '') ? null : $prefix);
            AuditLog::write('field_masks', 'updated', "Поле «{$label}»: постоянная часть адреса — " . ($prefix ?: ($f['prefix'] ?? '')), ['subject_type' => 'field', 'subject_id' => null, 'section_id' => null]);

            return back()->with('ok', "«{$label}»: сохранено");
        }

        abort_unless(FieldMasks::kind($field, $f) === 'mask', 422, 'К этому полю маска не применяется');
        $data = $r->validate(['masks' => ['nullable', 'array'], 'masks.*' => ['integer', 'exists:field_masks,id']]);
        $ids = array_values(array_unique(array_map('intval', $data['masks'] ?? [])));

        FieldMaskAssignment::where('sheet', $sheet)->where('field', $field)->delete();
        foreach ($ids as $pos => $id) {
            FieldMaskAssignment::create(['sheet' => $sheet, 'field' => $field, 'field_mask_id' => $id, 'position' => $pos]);
        }
        $names = FieldMask::whereIn('id', $ids)->pluck('pattern')->implode(', ');
        AuditLog::write('field_masks', 'updated', "Поле «{$label}»: маски — " . ($names ?: 'нет'), ['subject_type' => 'field', 'subject_id' => null, 'section_id' => null]);

        return back()->with('ok', $ids ? "«{$label}»: масок — " . count($ids) : "«{$label}»: маска снята");
    }
}
