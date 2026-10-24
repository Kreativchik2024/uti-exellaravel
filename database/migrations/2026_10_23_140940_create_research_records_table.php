<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('research_records', function (Blueprint $table) {
            $table->id();
            $table->date('record_date');
            $table->unsignedInteger('seq')->nullable();
            $table->string('worker1', 120);
            $table->string('worker2', 120)->nullable();
            $table->string('worker3', 120)->nullable();
            $table->string('worker4', 120)->nullable();
            $table->string('worker5', 120)->nullable();
            $table->string('fuselage_no', 64)->nullable();
            $table->string('fuselage_serial', 120)->nullable();
            $table->string('frame_serial', 120)->nullable();
            $table->string('bracket_serial', 120)->nullable();
            $table->text('reported_defect')->nullable();
            $table->string('rejected_by', 120)->nullable();
            $table->text('analysis_actions')->nullable();
            $table->text('found_defect')->nullable();
            $table->text('result')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['record_date','created_by']);
        });
    }
    public function down(): void { Schema::dropIfExists('research_records'); }
};