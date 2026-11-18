<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->enum('role', ['admin', 'master', 'executor', 'otk'])
                  ->default('executor')
                  ->change();
        });
    }

    public function down(): void
    {
        // Пользователи с ролью ОТК переводятся в исполнителей перед откатом enum
        \Illuminate\Support\Facades\DB::table('users')->where('role', 'otk')->update(['role' => 'executor']);

        Schema::table('users', function (Blueprint $table) {
            $table->enum('role', ['admin', 'master', 'executor'])
                  ->default('executor')
                  ->change();
        });
    }
};
