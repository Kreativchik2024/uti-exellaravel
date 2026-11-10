<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Участки производства. У каждого участка — свои вкладки учёта
 * (config/sheets.php → 'section'), своя статистика и свои новости.
 * Пользователь видит только назначенные ему участки (админ — все).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sections', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 50)->unique();          // связь с config/sheets.php
            $table->string('name', 120);
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->boolean('has_firmware')->default(false); // раздел «Прошивки»
            $table->timestamps();
        });

        Schema::create('section_user', function (Blueprint $table) {
            $table->foreignId('section_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->primary(['section_id', 'user_id']);
        });

        // NULL — новость для всех участков
        Schema::table('news', function (Blueprint $table) {
            $table->foreignId('section_id')->nullable()->after('id')->constrained()->nullOnDelete();
        });

        // Всё, что есть сейчас, — это участок испытаний
        $id = DB::table('sections')->insertGetId([
            'slug' => 'ispytaniya', 'name' => 'Участок испытаний',
            'sort_order' => 1, 'is_active' => true, 'has_firmware' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('users')->orderBy('id')->select('id')->chunk(500, function ($users) use ($id) {
            DB::table('section_user')->insert($users->map(fn ($u) => ['section_id' => $id, 'user_id' => $u->id])->all());
        });

        DB::table('news')->update(['section_id' => $id]);
    }

    public function down(): void
    {
        Schema::table('news', function (Blueprint $table) {
            $table->dropConstrainedForeignId('section_id');
        });
        Schema::dropIfExists('section_user');
        Schema::dropIfExists('sections');
    }
};
