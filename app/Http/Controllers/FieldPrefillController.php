<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Setting;
use App\Services\FieldPrefills;
use Illuminate\Http\Request;

/**
 * Админ → Заготовки полей: для каждого поля ПГП — текст, который уже стоит в пустом поле формы (например «УМН-»).
 * Строгой проверки нет: заготовку можно стереть или изменить. У IP — постоянная часть адреса.
 */
class FieldPrefillController extends Controller
{
    private function sheetCfg(string $sheet): array
    {
        abort_unless(in_array($sheet, FieldPrefills::SHEETS, true), 404);

        return config("sheets.map.$sheet");
    }

    public function index(Request $r, FieldPrefills $svc)
    {
        $sheet = (string) $r->query('sheet', FieldPrefills::SHEETS[0]);
        $cfg = $this->sheetCfg($sheet);

        $fields = [];
        foreach ($cfg['fields'] as $name => $f) {
            $kind = FieldPrefills::kind($name, $f);
            $fields[] = [
                'name'  => $name,
                'label' => $f['label'] ?? $name,
                'type'  => $f['type'] ?? 'text',
                'kind'  => $kind,
                'value' => match ($kind) {
                    'text'   => $svc->get($sheet, $name),
                    'prefix' => $svc->prefix($sheet, $name, (string) ($f['prefix'] ?? '')),
                    default  => null,
                },
                'defaultPrefix' => $f['prefix'] ?? null,
            ];
        }

        return view('field-prefills.index', compact('sheet', 'cfg', 'fields'));
    }

    /** Сохранить все заготовки вкладки разом. */
    public function save(Request $r, string $sheet)
    {
        $cfg = $this->sheetCfg($sheet);
        $data = $r->validate([
            'prefill'   => ['nullable', 'array'],
            'prefill.*' => ['nullable', 'string', 'max:120'],
            'prefix'    => ['nullable', 'array'],
            'prefix.*'  => ['nullable', 'string', 'max:20', 'regex:/^(\d{1,3}\.){1,3}$/'],
        ], ['prefix.*.regex' => 'Постоянная часть IP — числа с точками и точка в конце, например 192.168.1.']);

        $changes = [];
        foreach ($cfg['fields'] as $name => $f) {
            $kind = FieldPrefills::kind($name, $f);
            $label = $f['label'] ?? $name;

            if ($kind === 'text' && array_key_exists($name, $data['prefill'] ?? [])) {
                $new = (string) ($data['prefill'][$name] ?? '');
                $new = trim($new) === '' ? '' : ltrim($new);   // пробел в конце оставляем — «УМН » так и задумано
                $old = (string) Setting::get(FieldPrefills::key($sheet, $name), '');
                if ($new !== $old) {
                    Setting::set(FieldPrefills::key($sheet, $name), $new === '' ? null : $new);
                    $changes[] = "«{$label}»: " . ($new === '' ? 'без заготовки' : "«{$new}»");
                }
            }

            if ($kind === 'prefix' && array_key_exists($name, $data['prefix'] ?? [])) {
                $new = trim((string) ($data['prefix'][$name] ?? ''));
                $store = $new === '' || $new === ($f['prefix'] ?? '') ? null : $new;
                if ($store !== Setting::get(FieldPrefills::prefixKey($sheet, $name))) {
                    Setting::set(FieldPrefills::prefixKey($sheet, $name), $store);
                    $changes[] = "«{$label}»: постоянная часть адреса " . ($store ?? ($f['prefix'] ?? ''));
                }
            }
        }

        if ($changes) {
            AuditLog::write('field_prefills', 'updated', 'Заготовки полей ' . ($cfg['title'] ?? $sheet) . ': ' . implode('; ', $changes), ['section_id' => null]);
        }

        return back()->with('ok', $changes ? 'Сохранено: ' . count($changes) : 'Изменений нет');
    }
}
