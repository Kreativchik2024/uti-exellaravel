<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Архив стёртого справочника кодов: коды в записях учёта остаются, а по описанию отсюда
 * они при следующей загрузке привязываются к новым кодам (тот же код или похожее описание).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fault_code_archive', function (Blueprint $table) {
            $table->id();
            $table->string('code', 100)->unique();
            $table->string('description', 255)->nullable();
            $table->text('hint')->nullable();
            $table->string('group_label', 400)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fault_code_archive');
    }
};
