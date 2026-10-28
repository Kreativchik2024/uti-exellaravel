<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('record_employee', function (Blueprint $table) {
            $table->id();

            // Полиморфная связь: тип модели (slug из sheets.map) + id записи
            $table->string('record_type', 120);
            $table->unsignedBigInteger('record_id');

            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();

            // Позиция = номер поля (1..5 = worker1..worker5)
            $table->unsignedTinyInteger('position')->default(1);

            $table->timestamps();

            $table->unique(['record_type', 'record_id', 'employee_id'], 'rec_emp_unique');
            $table->index(['record_type', 'record_id']);
            $table->index('employee_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('record_employee');
    }
};