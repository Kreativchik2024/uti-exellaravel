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
            if (!Schema::hasColumn('pgp_records', 'cross3_worker')) {
                $table->string('cross3_worker', 120)->nullable()->after('cross3_number');
            }
            if (!Schema::hasColumn('pgp_records', 'cross3_record_date')) {
                $table->date('cross3_record_date')->nullable()->after('cross3_worker');
            }
            if (!Schema::hasColumn('pgp_records', 'cross3_health')) {
                $table->string('cross3_health', 20)->nullable()->after('cross3_record_date');
            }
            if (!Schema::hasColumn('pgp_records', 'cross3_status_text')) {
                $table->string('cross3_status_text', 255)->nullable()->after('cross3_health');
            }

            // Снимок кронштейна с камерой
            if (!Schema::hasColumn('pgp_records', 'bracket_worker')) {
                $table->string('bracket_worker', 120)->nullable()->after('bracket_serial');
            }
            if (!Schema::hasColumn('pgp_records', 'bracket_record_date')) {
                $table->date('bracket_record_date')->nullable()->after('bracket_worker');
            }
            if (!Schema::hasColumn('pgp_records', 'bracket_health')) {
                $table->string('bracket_health', 20)->nullable()->after('bracket_record_date');
            }
            if (!Schema::hasColumn('pgp_records', 'bracket_status_text')) {
                $table->string('bracket_status_text', 255)->nullable()->after('bracket_health');
            }

            // Снимок манета
            if (!Schema::hasColumn('pgp_records', 'manet_worker')) {
                $table->string('manet_worker', 120)->nullable()->after('manet_number');
            }
            if (!Schema::hasColumn('pgp_records', 'manet_record_date')) {
                $table->date('manet_record_date')->nullable()->after('manet_worker');
            }
            if (!Schema::hasColumn('pgp_records', 'manet_health')) {
                $table->string('manet_health', 20)->nullable()->after('manet_record_date');
            }
            if (!Schema::hasColumn('pgp_records', 'manet_status_text')) {
                $table->string('manet_status_text', 255)->nullable()->after('manet_health');
            }
        });
    }

    public function down(): void
    {
        Schema::table('pgp_records', function (Blueprint $table) {
            foreach ([
                'cross3_worker', 'cross3_record_date', 'cross3_health', 'cross3_status_text',
                'bracket_worker', 'bracket_record_date', 'bracket_health', 'bracket_status_text',
                'manet_worker', 'manet_record_date', 'manet_health', 'manet_status_text',
            ] as $col) {
                if (Schema::hasColumn('pgp_records', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};