<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Согласие с правилами и на обработку персональных данных: когда и с какой редакцией. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('consent_version', 20)->nullable()->after('last_login_at');
            $table->timestamp('consent_at')->nullable()->after('consent_version');
            $table->string('consent_ip', 45)->nullable()->after('consent_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['consent_version', 'consent_at', 'consent_ip']);
        });
    }
};
