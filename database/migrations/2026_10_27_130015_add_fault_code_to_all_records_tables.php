<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Таблицы, в которые добавляем fault_code.
     * Имена взяты из config/sheets.php (модели -> таблицы).
     * Если реальные имена таблиц отличаются — поправьте здесь.
     */
    private array $tables = [
        'pgp_records',
        'comm_module_records',
        'cross1_records',
        'cross3_records',
        'mini_pc_records',
        'research_records',
        'technologist_records',
        'pc_check_records',
        'sophon_check_records',
        'software_install_records',
        'motor_regulator_records',
        'camera_bracket_records',
        'router_firmware_records',
    ];

    public function up(): void
    {
        foreach ($this->tables as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            if (Schema::hasColumn($table, 'fault_code')) {
                continue;
            }
            Schema::table($table, function (Blueprint $t) {
                $t->string('fault_code', 100)->nullable()->after('record_date');
            });
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'fault_code')) {
                Schema::table($table, function (Blueprint $t) {
                    $t->dropColumn('fault_code');
                });
            }
        }
    }
};