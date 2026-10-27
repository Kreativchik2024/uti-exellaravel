<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('speed_sensor_records', function (Blueprint $table) {
            $table->id();
            $table->date('record_date');
            $table->string('serial_number');
            $table->string('is_working')->default('Да');
            $table->unsignedInteger('runs_count')->default(3);
            $table->string('worker1')->nullable();
            $table->string('worker2')->nullable();
            $table->string('worker3')->nullable();
            $table->string('worker4')->nullable();
            $table->string('worker5')->nullable();
            $table->text('notes')->nullable();
            $table->string('fault_code')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('speed_sensor_records');
    }
};