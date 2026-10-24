<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->enum('role', ['admin', 'master', 'executor'])
                  ->default('executor')
                  ->after('email');

            $table->boolean('is_active')->default(false)->after('role');
            $table->timestamp('last_login_at')->nullable()->after('is_active');
            $table->foreignId('approved_by')->nullable()->after('last_login_at')
                  ->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable()->after('approved_by');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['approved_by']);
            $table->dropColumn(['role', 'is_active', 'last_login_at', 'approved_by', 'approved_at']);
        });
    }
};