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
        Schema::table('camera_bracket_records', function (Blueprint $table) {
            // Добавляем столбец frame_serial после serial_number
            // nullable() — чтобы старые записи не ломались
            $table->string('frame_serial')->nullable()->after('serial_number');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('camera_bracket_records', function (Blueprint $table) {
            $table->dropColumn('frame_serial');
        });
    }
};
