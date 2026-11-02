<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('active_worker_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('session_id', 100)->index();
            $table->string('sheet', 50)->nullable();
            $table->unsignedBigInteger('record_id')->nullable();
            $table->timestamp('last_heartbeat_at')->index();
            $table->timestamps();

            $table->unique(['employee_id', 'session_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('active_worker_sessions');
    }
};