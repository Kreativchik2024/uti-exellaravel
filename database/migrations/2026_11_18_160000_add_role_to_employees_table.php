<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Роль теперь задаётся у сотрудника; учётная запись получает её автоматически (App\Models\Employee)
        Schema::table('employees', function (Blueprint $table) {
            $table->string('role', 20)->default('executor')->after('department');
        });

        // Ничего не меняется: сотрудник получает текущую роль своей учётной записи
        DB::table('employees')->whereNotNull('user_id')->orderBy('id')->each(function ($e) {
            $role = DB::table('users')->where('id', $e->user_id)->value('role');
            if ($role) DB::table('employees')->where('id', $e->id)->update(['role' => $role]);
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn('role');
        });
    }
};
