<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * «Кронштейн с камерой»:
 *  - assembled_by — кто собирал кронштейн (необязательно);
 *  - flash_* — снимок подсказки по № камеры из «Ночная камера в сборе с дневной»: кто и когда
 *    прошил камеру (как bracket_* / cross3_* у ПГП — сохраняется вместе с записью).
 */
return new class extends Migration
{
    private const COLUMNS = ['assembled_by', 'flash_worker', 'flash_record_date', 'flash_health', 'flash_status_text'];

    public function up(): void
    {
        Schema::table('camera_bracket_records', function (Blueprint $t) {
            if (!Schema::hasColumn('camera_bracket_records', 'assembled_by')) {
                $t->string('assembled_by', 120)->nullable()->after('serial_number');
            }
            if (!Schema::hasColumn('camera_bracket_records', 'flash_worker')) {
                $t->string('flash_worker', 500)->nullable()->after('camera_serial');
                $t->date('flash_record_date')->nullable()->after('flash_worker');
                $t->string('flash_health', 20)->nullable()->after('flash_record_date');
                $t->string('flash_status_text', 500)->nullable()->after('flash_health');
            }
        });
    }

    public function down(): void
    {
        Schema::table('camera_bracket_records', function (Blueprint $t) {
            foreach (self::COLUMNS as $c) {
                if (Schema::hasColumn('camera_bracket_records', $c)) $t->dropColumn($c);
            }
        });
    }
};
