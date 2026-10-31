<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pgp_records', function (Blueprint $table) {
            $table->string('frame_serial', 120)->nullable()->after('fuselage_number');
            $table->string('bracket_serial', 120)->nullable()->after('frame_serial');
        });
    }

    public function down(): void
    {
        Schema::table('pgp_records', function (Blueprint $table) {
            $table->dropColumn(['frame_serial', 'bracket_serial']);
        });
    }
};