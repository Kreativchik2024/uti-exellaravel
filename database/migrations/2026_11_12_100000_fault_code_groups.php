<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Категории кодов неисправностей: префикс кода (К3 → К3-001) и вкладки учёта, в которых
 * категория выпадает. У кода — категория, отметка «новый, проверить» и кто добавил.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fault_code_groups', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('prefix', 12)->unique();          // К3 → коды К3-001, К3-002…
            $table->json('sheets')->nullable();               // вкладки (slug), где выпадают коды категории
            $table->foreignId('section_id')->nullable()->constrained('sections')->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('fault_codes', function (Blueprint $table) {
            $table->foreignId('group_id')->nullable()->after('section_id')->constrained('fault_code_groups')->nullOnDelete();
            $table->boolean('needs_review')->default(false)->after('is_active');   // добавлен автоматически — проверить
            $table->foreignId('created_by')->nullable()->after('needs_review')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('fault_codes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('created_by');
            $table->dropColumn('needs_review');
            $table->dropConstrainedForeignId('group_id');
        });
        Schema::dropIfExists('fault_code_groups');
    }
};
