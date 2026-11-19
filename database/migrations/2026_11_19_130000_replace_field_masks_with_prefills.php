<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Маски полей заменены заготовками (App\Services\FieldPrefills): в поле заранее стоит текст, дальше — свободный ввод.
 * Из уже созданных масок переносится постоянная часть в начале первой маски поля («УМН-9999» → «УМН-»).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('field_mask_assignments') && Schema::hasTable('field_masks')) {
            $rows = DB::table('field_mask_assignments as a')->join('field_masks as m', 'm.id', '=', 'a.field_mask_id')
                ->orderBy('a.position')->orderBy('a.id')->get(['a.sheet', 'a.field', 'm.pattern']);
            $done = [];
            foreach ($rows as $r) {
                $key = "prefill.{$r->sheet}.{$r->field}";
                if (isset($done[$key])) continue;
                $done[$key] = true;
                $lead = $this->lead((string) $r->pattern);
                if ($lead === '' || DB::table('settings')->where('key', $key)->exists()) continue;
                DB::table('settings')->insert(['key' => $key, 'value' => $lead, 'created_at' => now(), 'updated_at' => now()]);
            }
        }

        Schema::dropIfExists('field_mask_assignments');
        Schema::dropIfExists('field_masks');
    }

    /** Постоянные символы в начале маски (до первого места для ввода). */
    private function lead(string $pattern): string
    {
        $out = '';
        $chars = mb_str_split($pattern);
        for ($i = 0, $n = count($chars); $i < $n; $i++) {
            $c = $chars[$i];
            if ($c === '\\' && $i + 1 < $n) { $out .= $chars[++$i]; continue; }
            if (in_array($c, ['9', '0', '#', 'A', 'А', 'Я', '*', '?', '_'], true)) break;
            $out .= $c;
        }

        return $out;
    }

    public function down(): void
    {
        // Маски не восстанавливаются: заготовки остаются в настройках
    }
};
