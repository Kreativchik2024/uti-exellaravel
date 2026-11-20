<?php

namespace App\Http\Controllers\Concerns;

use App\Services\SectionContext;

/** Настройки вкладки записей на текущем участке (RecordController, RecordReviewController). */
trait RecordSheets
{
    /** Конфиг вкладки с проверкой доступа к её участку (см. SectionContext::sheet). */
    private function cfg(string $sheet): array
    {
        $cfg = app(SectionContext::class)->sheet($sheet);

        // 'options_from' => 'technologists': список — активные технологи, проверка — любой технолог,
        // в т.ч. отключённый, или уже записанное значение (чтобы старую запись можно было пересохранить)
        foreach ($cfg['fields'] ?? [] as $name => $f) {
            if (($f['options_from'] ?? null) !== 'technologists') continue;
            $tech = app(\App\Services\Technologists::class);
            $options = $tech->names();
            $allowed = $tech->allNames();
            if ($id = (int) request()->route('id')) {
                $current = $cfg['model']::query()->whereKey($id)->value($name);
                if (filled($current)) {
                    $allowed[] = $current;
                    if (! in_array($current, $options, true)) $options[] = $current;
                }
            }
            $cfg['fields'][$name]['options'] = $options;
            $rules = is_array($f['rules']) ? $f['rules'] : explode('|', (string) $f['rules']);
            $cfg['fields'][$name]['rules'] = [...$rules, \Illuminate\Validation\Rule::in($allowed)];
        }

        // Заготовки полей, которые настроил админ (Админ → Заготовки полей)
        return app(\App\Services\FieldPrefills::class)->apply($cfg, $sheet);
    }
}
