<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pgp_records', function (Blueprint $table) {
            $table->string('cross3_worker', 120)->nullable()->after('cross3_number');
            $table->string('cross3_status', 50)->nullable()->after('cross3_worker');
            $table->string('bracket_worker', 120)->nullable()->after('bracket_serial');
            $table->string('bracket_status', 50)->nullable()->after('bracket_worker');
        });
    }

    public function down(): void
    {
        Schema::table('pgp_records', function (Blueprint $table) {
            $table->dropColumn(['cross3_worker', 'cross3_status', 'bracket_worker', 'bracket_status']);
        });
    }
};