<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Фото к записям учёта (любой вкладки): WebP + миниатюра, файлы в storage/app/private/records. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('record_photos', function (Blueprint $table) {
            $table->id();
            $table->string('sheet', 60);                  // вкладка (slug из config/sheets.php)
            $table->unsignedBigInteger('record_id');
            $table->string('path');
            $table->string('thumb_path');
            $table->string('original_name')->nullable();
            $table->unsignedInteger('size')->default(0);
            $table->unsignedSmallInteger('width')->nullable();
            $table->unsignedSmallInteger('height')->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['sheet', 'record_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('record_photos');
    }
};
