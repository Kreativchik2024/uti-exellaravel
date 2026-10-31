<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pgp_records', function (Blueprint $table) {
            // Снимок Кросс 3
            $table->string('cross3_worker', 120)->nullable()->after('cross3_number');
            $table->date('cross3_record_date')->nullable()->after('cross3_worker');
            $table->string('cross3_health', 20)->nullable()->after('cross3_record_date');
            $table->string('cross3_status_text', 255)->nullable()->after('cross3_health');

            // Снимок кронштейна с камерой
            $table->string('bracket_worker', 120)->nullable()->after('bracket_serial');
            $table->date('bracket_record_date')->nullable()->after('bracket_worker');
            $table->string('bracket_health', 20)->nullable()->after('bracket_record_date');
            $table->string('bracket_status_text', 255)->nullable()->after('bracket_health');

            // Снимок манета
            $table->string('manet_worker', 120)->nullable()->after('manet_number');
            $table->date('manet_record_date')->nullable()->after('manet_worker');
            $table->string('manet_health', 20)->nullable()->after('manet_record_date');
            $table->string('manet_status_text', 255)->nullable()->after('manet_health');
        });
    }

    public function down(): void
    {
        Schema::table('pgp_records', function (Blueprint $table) {
            $table->dropColumn([
                'cross3_worker', 'cross3_record_date', 'cross3_health', 'cross3_status_text',
                'bracket_worker', 'bracket_record_date', 'bracket_health', 'bracket_status_text',
                'manet_worker', 'manet_record_date', 'manet_health', 'manet_status_text',
            ]);
        });
    }
};