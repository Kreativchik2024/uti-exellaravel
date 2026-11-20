<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Исследования: подкатегории — не только борта, но и отдельные модули; у модуля — свой серийный номер. */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('research_records', function (Blueprint $table) {
            $table->string('module_serial', 120)->nullable()->after('bracket_serial');
            $table->index('category');
        });
    }

    public function down(): void
    {
        Schema::table('research_records', function (Blueprint $table) {
            $table->dropIndex(['category']);
            $table->dropColumn('module_serial');
        });
    }
};
