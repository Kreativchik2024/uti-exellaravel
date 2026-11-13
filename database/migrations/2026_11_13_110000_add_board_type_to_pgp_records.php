<?php

use App\Support\BoardTypes;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Тип борта ПГП (УМН / МН / СОН / … — список в config/board_types.php).
 * Строка, а не enum: новый тип добавляется в конфиге без изменения таблицы.
 * Существующие борта раскладываются по типам по номеру борта.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('pgp_records', 'board_type')) {
            Schema::table('pgp_records', function (Blueprint $table) {
                $table->string('board_type', 20)->nullable()->after('board_number')->index();
            });
        }

        DB::table('pgp_records')
            ->whereNull('board_type')
            ->whereNotNull('board_number')
            ->select('id', 'board_number')
            ->chunkById(500, function ($rows) {
                foreach ($rows as $row) {
                    $type = BoardTypes::detect($row->board_number);
                    if ($type !== null) {
                        DB::table('pgp_records')->where('id', $row->id)->update(['board_type' => $type]);
                    }
                }
            });
    }

    public function down(): void
    {
        Schema::table('pgp_records', function (Blueprint $table) {
            $table->dropIndex(['board_type']);
            $table->dropColumn('board_type');
        });
    }
};
