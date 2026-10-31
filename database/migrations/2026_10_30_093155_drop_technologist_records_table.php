<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('technologist_records');
    }

    public function down(): void
    {
        // Восстановление не требуется — данных в таблице не было
    }
};