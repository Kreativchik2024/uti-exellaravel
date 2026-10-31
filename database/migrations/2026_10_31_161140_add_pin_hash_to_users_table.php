<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('users', 'pin_hash')) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('pin_hash', 255)->nullable()->after('password');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'pin_hash')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('pin_hash');
            });
        }
    }
};