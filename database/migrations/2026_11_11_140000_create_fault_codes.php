<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Справочник кодов неисправностей: выпадающий список в поле «Код неисправности». */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fault_codes', function (Blueprint $table) {
            $table->id();
            $table->string('code', 100);
            $table->string('description', 255)->nullable();
            // null — код для всех участков
            $table->foreignId('section_id')->nullable()->constrained('sections')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['code', 'section_id']);
            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fault_codes');
    }
};
