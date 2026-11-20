<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Хранилище лицензий модулей связи (MANET): партии = папки в storage (диск local, comm_licenses/<партия>/),
 * в каждой — файл лицензии .dat и таблица серийников .xlsx (Device Serial Number, 32 hex).
 * Загружает администратор на сайте; MANET Flasher скачивает по ключу станции (api/station/comm-licenses).
 */
class CommLicenses
{
    public const DIR = 'comm_licenses';
    public const EXTS = ['dat', 'xlsx'];

    private function disk()
    {
        return Storage::disk('local');
    }

    /** Имя партии/файла → безопасное (буквы, цифры, пробел, . _ -); «#», «~» и прочее — «_» (ломают адреса). */
    public function batchName(string $name): string
    {
        $name = trim(preg_replace('/[^\p{L}\p{N} ._-]+/u', '_', $name), ' ._');

        return Str::limit($name, 80, '') ?: 'партия_' . now()->format('Ymd_His');
    }

    /** @return array<int, array{name: string, dat: ?array, xlsx: ?array, serials: int, updated: int}> */
    public function batches(): array
    {
        $out = [];
        foreach ($this->disk()->directories(self::DIR) as $dir) {
            $b = ['name' => basename($dir), 'dat' => null, 'xlsx' => null, 'serials' => 0, 'updated' => 0];
            foreach ($this->disk()->files($dir) as $f) {
                $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
                if (!in_array($ext, self::EXTS, true)) continue;
                $info = ['file' => basename($f), 'size' => $this->disk()->size($f), 'mtime' => $this->disk()->lastModified($f)];
                $b[$ext] = $info;
                $b['updated'] = max($b['updated'], $info['mtime']);
                if ($ext === 'xlsx') $b['serials'] = count($this->serials($f));
            }
            $out[] = $b;
        }
        usort($out, fn ($a, $b) => $b['updated'] <=> $a['updated']);

        return $out;
    }

    /** Серийники (32 hex) из таблицы .xlsx — xlsx это zip с XML, как читает и программа. */
    public function serials(string $path): array
    {
        if (!class_exists(\ZipArchive::class)) return [];
        $zip = new \ZipArchive();
        if ($zip->open($this->disk()->path($path)) !== true) return [];
        $text = '';
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $n = $zip->getNameIndex($i);
            if (str_starts_with($n, 'xl/') && str_ends_with($n, '.xml')) $text .= $zip->getFromIndex($i);
        }
        $zip->close();
        preg_match_all('/\b[0-9a-fA-F]{32}\b/', $text, $m);

        return array_values(array_unique(array_map('strtolower', $m[0])));
    }

    /** В каких партиях есть этот S/N. */
    public function findSerial(string $sn): array
    {
        $sn = strtolower(trim($sn));
        $hits = [];
        foreach ($this->batches() as $b) {
            if ($b['xlsx'] && in_array($sn, $this->serials(self::DIR . '/' . $b['name'] . '/' . $b['xlsx']['file']), true)) {
                $hits[] = $b['name'];
            }
        }

        return $hits;
    }

    /** Загрузить партию (новая или замена файлов в существующей). */
    public function store(string $batch, ?UploadedFile $dat, ?UploadedFile $xlsx): string
    {
        $batch = $this->batchName($batch);
        $dir = self::DIR . '/' . $batch;
        foreach (['dat' => $dat, 'xlsx' => $xlsx] as $ext => $file) {
            if (!$file) continue;
            foreach ($this->disk()->files($dir) as $old) {          // в партии — один файл каждого вида
                if (strtolower(pathinfo($old, PATHINFO_EXTENSION)) === $ext) $this->disk()->delete($old);
            }
            $name = $this->batchName(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME)) . '.' . $ext;
            $file->storeAs($dir, $name, 'local');
        }

        return $batch;
    }

    public function delete(string $batch): void
    {
        $this->disk()->deleteDirectory(self::DIR . '/' . $this->batchName($batch));
    }

    /** Для программ: все файлы с размером, временем и sha256 (путь — «партия/файл»). */
    public function listing(): array
    {
        $out = [];
        foreach ($this->batches() as $b) {
            foreach (self::EXTS as $ext) {
                if (!$b[$ext]) continue;
                $rel = $b['name'] . '/' . $b[$ext]['file'];
                $out[] = ['path' => $rel, 'size' => $b[$ext]['size'], 'mtime' => $b[$ext]['mtime'],
                          'sha256' => hash_file('sha256', $this->disk()->path(self::DIR . '/' . $rel))];
            }
        }

        return $out;
    }

    /** Полный путь файла из listing() или null (защита от ../ и чужих файлов). */
    public function fileFor(string $rel): ?string
    {
        foreach ($this->listing() as $f) {
            if ($f['path'] === $rel) return self::DIR . '/' . $rel;
        }

        return null;
    }
}
