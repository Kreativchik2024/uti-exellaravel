<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('camera_bracket_records', function (Blueprint $table) {
            $table->string('suspension_status', 50)->nullable()->after('barometer_status');
        });
    }

    public function down(): void
    {
        Schema::table('camera_bracket_records', function (Blueprint $table) {
            $table->dropColumn('suspension_status');
        });
    }
};