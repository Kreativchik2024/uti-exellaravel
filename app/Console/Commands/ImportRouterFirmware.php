<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ImportRouterFirmware extends Command
{
    protected $signature = 'import:router-firmware {file}';
    protected $description = 'Импорт записей router_firmware_records из CSV';

    public function handle(): int
    {
        $path = $this->argument('file');

        if (!is_file($path)) {
            $this->error("Файл не найден: {$path}");
            return self::FAILURE;
        }

        $handle = fopen($path, 'r');
        $chunk = [];
        $total = 0;
        $skipped = 0;

        while (($row = fgetcsv($handle, 0, ';')) !== false) {
            if (count($row) < 8) { $skipped++; continue; }

            [$date, $qty, $w1, $w2, $w3, $w4, $w5, $notes] = $row;

            $chunk[] = [
                'record_date' => $date,
                'qty'         => (int)$qty,
                'worker1'     => $w1,
                'worker2'     => $w2 !== '' ? $w2 : null,
                'worker3'     => $w3 !== '' ? $w3 : null,
                'worker4'     => $w4 !== '' ? $w4 : null,
                'worker5'     => $w5 !== '' ? $w5 : null,
                'notes'       => $notes !== '' ? $notes : null,
                'created_at'  => now(),
                'updated_at'  => now(),
            ];

            if (count($chunk) === 500) {
                DB::table('router_firmware_records')->insert($chunk);
                $total += count($chunk);
                $chunk = [];
            }
        }

        if ($chunk) {
            DB::table('router_firmware_records')->insert($chunk);
            $total += count($chunk);
        }
        fclose($handle);

        $this->info("Загружено: {$total}, пропущено: {$skipped}");
        return self::SUCCESS;
    }
}
