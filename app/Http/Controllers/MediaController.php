<?php

namespace App\Http\Controllers;

class MediaController extends Controller
{
    /**
     * Каталоги внутри storage/app/private, которые разрешено отдавать через /media.
     * Всё остальное (бэкапы, прошивки и т.п.) через этот маршрут недоступно.
     */
    private const ALLOWED_DIRS = ['news'];

    public function show(string $path)
    {
        $root = realpath(storage_path('app/private'));
        abort_unless($root !== false, 404);

        // realpath убирает "..", поэтому выход за пределы каталога не сработает
        $full = realpath($root . DIRECTORY_SEPARATOR . ltrim($path, '/\\'));
        abort_unless($full !== false && is_file($full), 404);

        $allowed = false;
        foreach (self::ALLOWED_DIRS as $dir) {
            $base = realpath($root . DIRECTORY_SEPARATOR . $dir);
            if ($base !== false && str_starts_with($full, $base . DIRECTORY_SEPARATOR)) {
                $allowed = true;
                break;
            }
        }
        abort_unless($allowed, 404);

        return response()->file($full, [
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
