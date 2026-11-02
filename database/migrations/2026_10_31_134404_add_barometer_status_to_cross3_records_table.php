<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('cross3_records', 'barometer_status')) {
            Schema::table('cross3_records', function (Blueprint $table) {
                $table->string('barometer_status', 32)->nullable()->after('compass_version');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('cross3_records', 'barometer_status')) {
            Schema::table('cross3_records', function (Blueprint $table) {
                $table->dropColumn('barometer_status');
            });
        }
    }
};
