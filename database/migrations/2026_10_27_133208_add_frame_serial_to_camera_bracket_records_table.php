<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Колонка уже создаётся в create_camera_bracket_records_table —
        // добавляем только на старых базах, где её нет.
        if (Schema::hasColumn('camera_bracket_records', 'frame_serial')) {
            return;
        }

        Schema::table('camera_bracket_records', function (Blueprint $table) {
            $table->string('frame_serial')->nullable()->after('serial_number');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Колонкой владеет create_camera_bracket_records_table — здесь ничего не удаляем.
    }
};
