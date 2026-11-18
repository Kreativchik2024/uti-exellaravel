<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Исследование, заведённое автоматически по браку ПГП
        Schema::table('research_records', function (Blueprint $table) {
            $table->foreignId('pgp_record_id')->nullable()->after('id')
                  ->constrained('pgp_records')->nullOnDelete();
        });

        // Комментарии к исследованию: их может быть много, видна вся история
        Schema::create('research_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('research_record_id')->constrained('research_records')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('body');
            $table->timestamps();
            $table->index(['research_record_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('research_comments');
        Schema::table('research_records', function (Blueprint $table) {
            $table->dropForeign(['pgp_record_id']);
            $table->dropColumn('pgp_record_id');
        });
    }
};
