<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pgp_records', function (Blueprint $table) {
            $table->unsignedSmallInteger('peripheral_number')->nullable()->after('post_number');
        });
    }

    public function down(): void
    {
        Schema::table('pgp_records', function (Blueprint $table) {
            $table->dropColumn('peripheral_number');
        });
    }
};