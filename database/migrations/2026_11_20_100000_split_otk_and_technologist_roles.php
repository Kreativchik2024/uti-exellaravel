<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Роль «Технолог» получает все права прежней группы ОТК; все, кто был в ОТК, становятся технологами.
 * Роль «otk» остаётся как «Представитель ОТК» — просто список людей для поля ПГП, без прав и входа на сайт.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->enum('role', ['admin', 'master', 'executor', 'otk', 'repair', 'technologist'])
                  ->default('executor')
                  ->change();
        });

        DB::table('users')->where('role', 'otk')->update(['role' => 'technologist']);
        DB::table('employees')->where('role', 'otk')->update(['role' => 'technologist']);
    }

    public function down(): void
    {
        // Представители ОТК без прав откатываются в исполнители, технологи — обратно в ОТК
        DB::table('users')->where('role', 'otk')->update(['role' => 'executor']);
        DB::table('employees')->where('role', 'otk')->update(['role' => 'executor']);
        DB::table('users')->where('role', 'technologist')->update(['role' => 'otk']);
        DB::table('employees')->where('role', 'technologist')->update(['role' => 'otk']);

        Schema::table('users', function (Blueprint $table) {
            $table->enum('role', ['admin', 'master', 'executor', 'otk', 'repair'])
                  ->default('executor')
                  ->change();
        });
    }
};
