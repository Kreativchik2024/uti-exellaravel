<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('firmware_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->constrained('firmware_categories')->cascadeOnDelete();
            $table->string('version', 80);
            $table->enum('status', ['actual', 'deprecated', 'archive'])->default('actual');
            $table->string('file_path', 500);
            $table->unsignedBigInteger('file_size')->nullable();
            $table->char('sha256', 64)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('uploaded_at')->useCurrent();
            $table->timestamp('made_actual_at')->nullable();
            $table->timestamps();

            $table->unique(['category_id', 'version']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('firmware_versions');
    }
};