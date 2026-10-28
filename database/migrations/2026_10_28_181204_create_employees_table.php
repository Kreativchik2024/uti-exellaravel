<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->string('last_name');                 // Фамилия
            $table->string('first_name');                // Имя
            $table->string('middle_name')->nullable();   // Отчество
            $table->string('short_name');                // «Иванов И.И.» — для отображения
            $table->string('position')->nullable();      // Должность
            $table->string('department')->nullable();    // Участок/отдел
            $table->boolean('is_active')->default(true); // Уволенные — false
            $table->timestamps();

            $table->index('last_name');
            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employees');
    }
};