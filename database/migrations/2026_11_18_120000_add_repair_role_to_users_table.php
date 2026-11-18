<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->enum('role', ['admin', 'master', 'executor', 'otk', 'repair'])
                  ->default('executor')
                  ->change();
        });
    }

    public function down(): void
    {
        // Ремонтники переводятся в исполнителей перед откатом enum
        DB::table('users')->where('role', 'repair')->update(['role' => 'executor']);

        Schema::table('users', function (Blueprint $table) {
            $table->enum('role', ['admin', 'master', 'executor', 'otk'])
                  ->default('executor')
                  ->change();
        });
    }
};
