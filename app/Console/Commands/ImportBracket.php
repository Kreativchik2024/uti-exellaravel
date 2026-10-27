<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ImportBracket extends Command
{
    protected $signature = 'import:bracket {file}';
    protected $description = 'Импорт camera_bracket_records из CSV';

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

        while (($row = fgetcsv($handle, 0, ';', '"', '\\')) !== false) {
            if (count($row) < 14) { $skipped++; continue; }

            // 0:date 1:serial 2:frame(не используется) 3:cam 4:night 5:speed 6:baro
            // 7:fio1 8:fio2 9:fio3 10:w4 11:w5 12:notes 13:comments
            [$date, $serial, $frame, $cam, $night, $speed, $baro,
             $fio1, $fio2, $fio3, $w4, $w5, $notes, $comments] = $row;

            $dateDb = $this->normalizeDate($date);
            if ($dateDb === null || $fio1 === '') { $skipped++; continue; }

            $chunk[] = [
                'record_date'         => $dateDb,
                'serial_number'       => $serial ?: null,
                // 'frame_serial'     => — в таблице НЕТ такой колонки, убрали
                'camera_status'       => $cam ?: null,
                'night_camera_status' => $night ?: null,
                'speed_sensor_status' => $speed ?: null,
                'barometer_status'    => $baro ?: null,
                'worker1'             => $fio1,
                'worker2'             => $fio2 ?: null,
                'worker3'             => $fio3 ?: null,
                'worker4'             => $w4 ?: null,
                'worker5'             => $w5 ?: null,
                'notes'               => $notes ?: null,
                'comments'            => $comments ?: null,
                'created_at'          => now(),
                'updated_at'          => now(),
            ];

            if (count($chunk) === 200) {
                DB::table('camera_bracket_records')->insert($chunk);
                $total += count($chunk);
                $chunk = [];
            }
        }

        if ($chunk) {
            DB::table('camera_bracket_records')->insert($chunk);
            $total += count($chunk);
        }
        fclose($handle);

        $this->info("Загружено: {$total}, пропущено: {$skipped}");
        return self::SUCCESS;
    }

    private function normalizeDate(string $d): ?string
    {
        if (preg_match('/^(\d{2})\.(\d{2})\.(\d{4})$/', $d, $m)) {
            return "{$m[3]}-{$m[2]}-{$m[1]}";
        }
        return null;
    }
}
