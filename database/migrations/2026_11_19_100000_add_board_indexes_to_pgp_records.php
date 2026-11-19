<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Индексы для поиска борта по № моторамы / № фюзеляжа (рекламации ПГП, журнал борта). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pgp_records', function (Blueprint $table) {
            $table->index('frame_serial');
            $table->index('fuselage_number');
        });
    }

    public function down(): void
    {
        Schema::table('pgp_records', function (Blueprint $table) {
            $table->dropIndex(['frame_serial']);
            $table->dropIndex(['fuselage_number']);
        });
    }
};
