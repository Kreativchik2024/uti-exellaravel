<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $columns = [
            'cross3_worker'       => ['type' => 'string', 'after' => 'cross3_number',      'params' => [120]],
            'cross3_record_date'  => ['type' => 'date',   'after' => 'cross3_worker',      'params' => []],
            'cross3_health'       => ['type' => 'string', 'after' => 'cross3_record_date', 'params' => [16]],
            'cross3_status_text'  => ['type' => 'string', 'after' => 'cross3_health',      'params' => [255]],

            'bracket_worker'      => ['type' => 'string', 'after' => 'bracket_serial',     'params' => [120]],
            'bracket_record_date' => ['type' => 'date',   'after' => 'bracket_worker',     'params' => []],
            'bracket_health'      => ['type' => 'string', 'after' => 'bracket_record_date','params' => [16]],
            'bracket_status_text' => ['type' => 'string', 'after' => 'bracket_health',     'params' => [255]],

            'manet_worker'        => ['type' => 'string', 'after' => 'manet_number',       'params' => [120]],
            'manet_record_date'   => ['type' => 'date',   'after' => 'manet_worker',       'params' => []],
            'manet_health'        => ['type' => 'string', 'after' => 'manet_record_date',  'params' => [16]],
            'manet_status_text'   => ['type' => 'string', 'after' => 'manet_health',       'params' => [255]],
        ];

        foreach ($columns as $name => $cfg) {
            if (Schema::hasColumn('pgp_records', $name)) {
                continue;
            }

            Schema::table('pgp_records', function (Blueprint $table) use ($name, $cfg) {
                $col = $cfg['type'] === 'date'
                    ? $table->date($name)
                    : $table->string($name, $cfg['params'][0] ?? 255);

                $col->nullable();
                if (!empty($cfg['after'])) {
                    $col->after($cfg['after']);
                }
            });
        }
    }

    public function down(): void
    {
        // Ничего не откатываем: колонки критичны для работы приложения.
    }
};