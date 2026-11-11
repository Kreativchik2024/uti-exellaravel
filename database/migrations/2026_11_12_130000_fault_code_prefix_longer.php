<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Основа кода из журнала анализа отказов длинная: SYS_CAM_N.HW-CONN-LINE. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fault_code_groups', function (Blueprint $table) {
            $table->string('prefix', 80)->change();
        });
    }

    public function down(): void
    {
        Schema::table('fault_code_groups', function (Blueprint $table) {
            $table->string('prefix', 30)->change();
        });
    }
};
