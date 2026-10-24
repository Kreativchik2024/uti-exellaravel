<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('pgp_records', function (Blueprint $table) {
            $table->id();
            $table->date('record_date');
            $table->unsignedTinyInteger('post_number')->nullable();

            $table->string('worker1', 120);              // обязательный
            $table->string('worker2', 120)->nullable();
            $table->string('worker3', 120)->nullable();
            $table->string('worker4', 120)->nullable();
            $table->string('worker5', 120)->nullable();

            $table->time('work_start')->nullable();
            $table->time('work_end')->nullable();
            $table->string('board_number', 80)->nullable();
            $table->string('ip_address', 64)->nullable();
            $table->string('fuselage_number', 64)->nullable();

            $table->enum('status', ['Сдан','Не сдан','В работе','Исправен','Неисправен','В ремонт'])->default('Сдан');
            $table->text('defect_reason')->nullable();
            $table->text('actions_taken')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['record_date', 'post_number', 'status']);
            $table->index('created_by');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pgp_records');
    }
};