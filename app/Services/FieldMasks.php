<?php

namespace App\Services;

use App\Models\FieldMaskAssignment;
use App\Models\Setting;
use App\Support\InputMask;

/**
 * Маски полей вкладок, которые настраивает админ (Админ → Маски полей):
 *  - текстовые поля — одна или несколько масок из библиотеки (подходит любая);
 *  - IP (type ip_suffix) — постоянная часть адреса (префикс), вводится только последнее число.
 * Подмешивается в конфиг вкладки (Concerns\RecordSheets::cfg): форма получает маски, проверка — правило.
 */
class FieldMasks
{
    /** Вкладки, где админ настраивает маски. */
    public const SHEETS = ['pgp'];

    /** Поля, к которым маски не применяются (свой выбор из справочника). */
    private const SKIP_FIELDS = ['fault_code'];

    private ?array $memo = null;

    /** 'mask' — текстовое поле (маски), 'prefix' — IP (префикс), null — маска не применяется. */
    public static function kind(string $name, array $f): ?string
    {
        if (in_array($name, self::SKIP_FIELDS, true)) return null;

        return match ($f['type'] ?? 'text') {
            'text', 'combo' => 'mask',
            'ip_suffix'     => 'prefix',
            default         => null,
        };
    }

    /** @return array<string, list<\App\Models\FieldMask>> поле → маски по порядку */
    public function forSheet(string $sheet): array
    {
        $this->memo ??= [];
        if (! array_key_exists($sheet, $this->memo)) {
            $this->memo[$sheet] = FieldMaskAssignment::query()->where('sheet', $sheet)->with('mask')
                ->orderBy('position')->orderBy('id')->get()
                ->filter(fn ($a) => $a->mask)
                ->groupBy('field')->map(fn ($g) => $g->pluck('mask')->values()->all())->all();
        }

        return $this->memo[$sheet];
    }

    public static function prefixKey(string $sheet, string $field): string
    {
        return "mask.{$sheet}.{$field}.prefix";
    }

    public function prefix(string $sheet, string $field, string $default): string
    {
        return (string) (Setting::get(self::prefixKey($sheet, $field)) ?: $default);
    }

    /** Подмешать маски в конфиг вкладки. */
    public function apply(array $cfg, string $sheet): array
    {
        if (! in_array($sheet, self::SHEETS, true)) return $cfg;
        $masks = $this->forSheet($sheet);

        // Правка записи: уже сохранённое значение (внесённое до маски) пропускаем — иначе старую запись не пересохранить
        $current = null;
        if ($masks && ($id = (int) request()->route('id')) && ! empty($cfg['model'])) {
            $current = $cfg['model']::query()->whereKey($id)->first();
        }

        foreach ($cfg['fields'] ?? [] as $name => $f) {
            $kind = self::kind($name, $f);

            if ($kind === 'prefix') {
                $prefix = $this->prefix($sheet, $name, (string) ($f['prefix'] ?? ''));
                if ($prefix === ($f['prefix'] ?? '')) continue;
                $cfg['fields'][$name]['prefix'] = $prefix;
                $rules = is_array($f['rules']) ? $f['rules'] : explode('|', (string) $f['rules']);
                $rules = array_values(array_filter($rules, fn ($r) => ! (is_string($r) && str_starts_with($r, 'regex:'))));
                $rules[] = 'regex:/^' . preg_quote($prefix, '/') . '(25[0-4]|2[0-4]\d|1\d\d|[1-9]\d?)$/';
                $cfg['fields'][$name]['rules'] = $rules;
                continue;
            }

            if ($kind !== 'mask' || empty($masks[$name])) continue;

            $patterns = array_map(fn ($m) => $m->pattern, $masks[$name]);
            $label = $f['label'] ?? $name;
            $shown = implode(' или ', array_map([InputMask::class, 'placeholder'], $patterns));
            $legend = InputMask::legend($patterns);

            $cfg['fields'][$name]['masks'] = $patterns;
            $cfg['fields'][$name]['placeholder'] = InputMask::placeholder($patterns[0]);
            $hints = array_filter(array_map(fn ($m) => $m->hint, $masks[$name]));
            $cfg['fields'][$name]['hint'] = trim(($f['hint'] ?? '') . ' Формат: ' . $shown . ($legend ? " ({$legend})" : '') . ($hints ? '. ' . implode('. ', $hints) : ''));

            $rules = is_array($f['rules']) ? $f['rules'] : explode('|', (string) $f['rules']);
            $was = $current ? trim((string) $current->{$name}) : null;
            $rules[] = function (string $attr, mixed $value, \Closure $fail) use ($patterns, $label, $shown, $was) {
                $v = trim((string) $value);
                if ($v === '' || ($was !== null && $was !== '' && $v === $was)) return;
                foreach ($patterns as $p) if (InputMask::matches($p, $v)) return;
                $fail("{$label}: введите по формату {$shown}.");
            };
            $cfg['fields'][$name]['rules'] = $rules;
        }

        return $cfg;
    }
}
