<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Маски ввода полей (Админ → Маски полей): библиотека масок и какие маски у какого поля вкладки. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('field_masks', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('pattern', 120);    // 9 — цифра, A — буква, * — буква или цифра (App\Support\InputMask)
            $table->string('hint', 255)->nullable();
            $table->timestamps();
        });

        Schema::create('field_mask_assignments', function (Blueprint $table) {
            $table->id();
            $table->string('sheet', 60);
            $table->string('field', 60);
            $table->foreignId('field_mask_id')->constrained('field_masks')->cascadeOnDelete();
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
            $table->unique(['sheet', 'field', 'field_mask_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('field_mask_assignments');
        Schema::dropIfExists('field_masks');
    }
};
