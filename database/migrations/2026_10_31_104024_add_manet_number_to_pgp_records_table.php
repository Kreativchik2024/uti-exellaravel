<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pgp_records', function (Blueprint $table) {
            $table->string('manet_number', 120)->nullable()->after('bracket_serial');
        });
    }

    public function down(): void
    {
        Schema::table('pgp_records', function (Blueprint $table) {
            $table->dropColumn('manet_number');
        });
    }
};