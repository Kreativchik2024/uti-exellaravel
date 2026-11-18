<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('pgp_records', function (Blueprint $table) {
            // Кто из технологов (группа ОТК) принял борт; в старых записях — пусто
            $table->string('technologist', 120)->nullable()->after('status');
            // Сколько минут ждали технолога; не заполнено — не участвует в среднем
            $table->unsignedSmallInteger('wait_minutes')->nullable()->after('technologist');
            $table->index(['technologist', 'record_date']);
        });
    }

    public function down(): void
    {
        Schema::table('pgp_records', function (Blueprint $table) {
            $table->dropIndex(['technologist', 'record_date']);
            $table->dropColumn(['technologist', 'wait_minutes']);
        });
    }
};
