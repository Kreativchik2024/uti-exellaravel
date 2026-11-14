<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Участок «Сборка камер». Вкладки участка — в config/sheets.php с 'section' => 'sborka-kamer'.
 * Пользователей на участок назначает админ (Пользователи → участки), админ видит все участки.
 */
return new class extends Migration
{
    private const SLUG = 'sborka-kamer';

    public function up(): void
    {
        if (DB::table('sections')->where('slug', self::SLUG)->exists()) return;

        DB::table('sections')->insert([
            'slug'         => self::SLUG,
            'name'         => 'Сборка камер',
            'sort_order'   => (int) DB::table('sections')->max('sort_order') + 1,
            'is_active'    => true,
            'has_firmware' => false,
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);
    }

    public function down(): void
    {
        // Участок с назначенными людьми / новостями не удаляем молча
        $id = DB::table('sections')->where('slug', self::SLUG)->value('id');
        if ($id && DB::table('section_user')->where('section_id', $id)->doesntExist()
            && DB::table('news')->where('section_id', $id)->doesntExist()) {
            DB::table('sections')->where('id', $id)->delete();
        }
    }
};
