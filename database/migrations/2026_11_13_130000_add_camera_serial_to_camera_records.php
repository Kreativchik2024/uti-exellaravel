<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * «№ камеры» (серийный номер, который отдаёт сама камера: MK0773PAZ02805) —
 * в «Ночная камера в сборе с дневной» (заполняет флешер после прошивки)
 * и в «Кронштейн с камерой» (заполняет считыватель). По нему видна вся цепочка исполнителей.
 */
return new class extends Migration
{
    private const TABLES = ['night_camera_assembly_records', 'camera_bracket_records'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            if (Schema::hasColumn($table, 'camera_serial')) continue;
            Schema::table($table, function (Blueprint $t) {
                $t->string('camera_serial', 120)->nullable()->after('record_date');
                $t->index('camera_serial');
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            if (!Schema::hasColumn($table, 'camera_serial')) continue;
            Schema::table($table, function (Blueprint $t) {
                $t->dropIndex(['camera_serial']);
                $t->dropColumn('camera_serial');
            });
        }
    }
};
