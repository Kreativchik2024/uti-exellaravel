<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('sophon_check_records', function (Blueprint $table) {
            $table->id();
            $table->date('record_date');
            $table->string('worker1', 120);
            $table->string('worker2', 120)->nullable();
            $table->string('worker3', 120)->nullable();
            $table->string('worker4', 120)->nullable();
            $table->string('worker5', 120)->nullable();
            $table->text('reported_defect')->nullable();
            $table->string('rejected_by', 120)->nullable();
            $table->text('found_defect')->nullable();
            $table->text('work_done')->nullable();
            $table->string('stand_test', 120)->nullable();
            $table->text('notes')->nullable();
            $table->string('status', 80)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['record_date','created_by']);
        });
    }
    public function down(): void { Schema::dropIfExists('sophon_check_records'); }
};