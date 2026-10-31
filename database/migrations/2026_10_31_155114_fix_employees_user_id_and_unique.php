<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1) Колонка user_id
        if (!Schema::hasColumn('employees', 'user_id')) {
            Schema::table('employees', function (Blueprint $table) {
                $table->foreignId('user_id')->nullable()->after('id');
            });
        }

        // 2) FK на users (если ещё нет)
        Schema::table('employees', function (Blueprint $table) {
            try {
                $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
            } catch (\Throwable $e) {
                // FK уже существует — игнорируем
            }
        });

        // 3) Уникальность: один User — один Employee
        if (Schema::hasColumn('employees', 'user_id')) {
            try {
                Schema::table('employees', function (Blueprint $table) {
                    $table->unique('user_id', 'employees_user_id_unique');
                });
            } catch (\Throwable $e) {
                // Индекс уже существует — игнорируем
            }
        }
    }

    public function down(): void
    {
        // Не откатываем — колонка нужна приложению.
    }
};