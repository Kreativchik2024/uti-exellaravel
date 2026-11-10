<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Журнал действий: кто, когда и что сделал (записи учёта, пользователи, вход в систему…). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->timestamp('created_at')->useCurrent()->index();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('user_name', 120)->nullable();      // имя на момент действия (пользователя могут удалить)
            $table->string('area', 30)->index();               // records, users, employees, news, firmware, sections, auth, profile
            $table->string('action', 40)->index();             // created, updated, deleted, login, role, …
            $table->string('subject_type', 60)->nullable();    // вкладка (pgp, cross3…) или сущность
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->foreignId('section_id')->nullable()->constrained('sections')->nullOnDelete();
            $table->string('summary', 255);
            $table->json('changes')->nullable();               // {поле: [было, стало]}
            $table->string('ip', 45)->nullable();

            $table->index(['subject_type', 'subject_id']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
