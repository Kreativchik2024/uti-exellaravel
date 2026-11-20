<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** В поле «Код неисправности» теперь можно несколько кодов через «; » — колонка шире (100 → 255). */
return new class extends Migration {
    public function up(): void
    {
        $this->resize(255);
    }

    public function down(): void
    {
        $this->resize(100);
    }

    private function resize(int $length): void
    {
        $tables = collect(config('sheets.map'))->pluck('model')->filter()
            ->map(fn ($m) => (new $m)->getTable())->unique();
        foreach ($tables as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'fault_code')) continue;
            Schema::table($table, fn (Blueprint $b) => $b->string('fault_code', $length)->nullable()->change());
        }
    }
};
