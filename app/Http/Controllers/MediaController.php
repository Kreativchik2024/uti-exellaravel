<?php

namespace App\Http\Controllers;

class MediaController extends Controller
{
    public function show(string $path)
    {
        abort_unless(auth()->check(), 403);

        $root = realpath(storage_path('app/private'));
        abort_unless($root !== false, 404);

        // realpath убирает "..", поэтому выход за пределы каталога не сработает
        $full = realpath($root . DIRECTORY_SEPARATOR . ltrim($path, '/\\'));
        abort_unless($full !== false && is_file($full), 404);

        // защита от чтения файлов вне storage/app/private (в т.ч. через симлинки)
        abort_unless(str_starts_with($full, $root . DIRECTORY_SEPARATOR), 403);

        return response()->file($full);
    }
}
