<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('technologist_records');
    }

    public function down(): void
    {
        // Восстанавливаем только если понадобится откат
        Schema::create('technologist_records', function (Blueprint $table) {
            $table->id();
            $table->date('record_date');
            $table->string('act_number', 80)->nullable();
            $table->string('node_name', 120)->nullable();
            $table->string('serial_number', 120)->nullable();
            $table->string('rejected_by', 120)->nullable();
            $table->text('defect_description')->nullable();
            $table->string('defect_node', 120)->nullable();
            $table->text('conclusion')->nullable();
            $table->string('technologist', 120)->nullable();
            $table->string('from_section', 120)->nullable();
            $table->string('to_section', 120)->nullable();
            $table->date('sent_to_repair_at')->nullable();
            $table->string('repair_status', 80)->nullable();
            $table->date('planned_ready_at')->nullable();
            $table->string('responsible', 120)->nullable();
            $table->date('actual_return_at')->nullable();
            $table->string('worker1', 120);
            $table->string('worker2', 120)->nullable();
            $table->string('worker3', 120)->nullable();
            $table->string('worker4', 120)->nullable();
            $table->string('worker5', 120)->nullable();
            $table->text('notes')->nullable();
            $table->string('fault_code', 100)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['record_date','created_by']);
        });
    }
};