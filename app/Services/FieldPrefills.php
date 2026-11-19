<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Http\Request;

/**
 * Заготовки полей (Админ → Заготовки полей): часть текста, которая уже стоит в пустом поле формы,
 * например «УМН-» в номере борта. Дальше — свободный ввод, строгой проверки нет: заготовку можно стереть или изменить.
 * Если к заготовке ничего не дописали — поле считается пустым.
 * У IP модуля связи (type ip_suffix) — постоянная часть адреса, вводится только последнее число.
 */
class FieldPrefills
{
    /** Вкладки, где админ задаёт заготовки. */
    public const SHEETS = ['pgp'];

    /** Поля со своим выбором из справочника — без заготовки. */
    private const SKIP_FIELDS = ['fault_code'];

    /** 'text' — заготовка текста, 'prefix' — постоянная часть IP, null — к полю не применяется. */
    public static function kind(string $name, array $f): ?string
    {
        if (in_array($name, self::SKIP_FIELDS, true)) return null;

        return match ($f['type'] ?? 'text') {
            'text', 'combo' => 'text',
            'ip_suffix'     => 'prefix',
            default         => null,
        };
    }

    public static function key(string $sheet, string $field): string
    {
        return "prefill.{$sheet}.{$field}";
    }

    /** Ключ постоянной части IP (прежнее имя настройки сохранено). */
    public static function prefixKey(string $sheet, string $field): string
    {
        return "mask.{$sheet}.{$field}.prefix";
    }

    public function get(string $sheet, string $field): string
    {
        return (string) Setting::get(self::key($sheet, $field), '');
    }

    public function prefix(string $sheet, string $field, string $default): string
    {
        return (string) (Setting::get(self::prefixKey($sheet, $field)) ?: $default);
    }

    /** Подмешать заготовки и постоянную часть IP в конфиг вкладки (Concerns\RecordSheets::cfg). */
    public function apply(array $cfg, string $sheet): array
    {
        if (! in_array($sheet, self::SHEETS, true)) return $cfg;

        foreach ($cfg['fields'] ?? [] as $name => $f) {
            $kind = self::kind($name, $f);

            if ($kind === 'text') {
                $text = $this->get($sheet, $name);
                if ($text !== '') $cfg['fields'][$name]['prefill'] = $text;
                continue;
            }

            if ($kind === 'prefix') {
                $prefix = $this->prefix($sheet, $name, (string) ($f['prefix'] ?? ''));
                if ($prefix === ($f['prefix'] ?? '')) continue;
                $cfg['fields'][$name]['prefix'] = $prefix;
                $rules = is_array($f['rules']) ? $f['rules'] : explode('|', (string) $f['rules']);
                $rules = array_values(array_filter($rules, fn ($r) => ! (is_string($r) && str_starts_with($r, 'regex:'))));
                $rules[] = 'regex:/^' . preg_quote($prefix, '/') . '(25[0-4]|2[0-4]\d|1\d\d|[1-9]\d?)$/';
                $cfg['fields'][$name]['rules'] = $rules;
            }
        }

        return $cfg;
    }

    /** Перед проверкой формы: поле, где осталась только заготовка, — пустое. */
    public static function clean(array $cfg, Request $r): void
    {
        foreach ($cfg['fields'] ?? [] as $name => $f) {
            if (! isset($f['prefill']) || ! $r->exists($name)) continue;
            if (trim((string) $r->input($name)) === trim($f['prefill'])) $r->merge([$name => null]);
        }
    }
}
