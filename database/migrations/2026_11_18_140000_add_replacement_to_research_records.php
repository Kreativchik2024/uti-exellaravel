<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('research_records', function (Blueprint $table) {
            // Что заменено при ремонте: узел из списка или свой вариант
            $table->string('replacement', 120)->nullable()->after('result');
            $table->index('replacement');
        });
    }

    public function down(): void
    {
        Schema::table('research_records', function (Blueprint $table) {
            $table->dropIndex(['replacement']);
            $table->dropColumn('replacement');
        });
    }
};
