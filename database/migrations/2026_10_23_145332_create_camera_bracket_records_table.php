<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('camera_bracket_records', function (Blueprint $table) {
            $table->id();
            $table->date('record_date');
            $table->string('serial_number', 120)->nullable();       // S/N кронштейна
            $table->string('frame_serial', 120)->nullable();        // № моторамы
            $table->string('camera_status', 80)->nullable();        // Работает / не работает
            $table->string('night_camera_status', 80)->nullable();
            $table->string('speed_sensor_status', 80)->nullable();
            $table->string('barometer_status', 80)->nullable();
            $table->string('worker1', 120);
            $table->string('worker2', 120)->nullable();
            $table->string('worker3', 120)->nullable();
            $table->string('worker4', 120)->nullable();
            $table->string('worker5', 120)->nullable();
            $table->text('notes')->nullable();
            $table->text('comments')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['record_date','created_by']);
        });
    }
    public function down(): void { Schema::dropIfExists('camera_bracket_records'); }
};