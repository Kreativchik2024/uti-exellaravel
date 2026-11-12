<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('night_camera_assembly_records', function (Blueprint $table) {
            // Галочка «Прошита вместе с дневной камерой»
            $table->boolean('flashed_with_day')->default(false)->after('qty');
        });
    }

    public function down(): void
    {
        Schema::table('night_camera_assembly_records', function (Blueprint $table) {
            $table->dropColumn('flashed_with_day');
        });
    }
};
