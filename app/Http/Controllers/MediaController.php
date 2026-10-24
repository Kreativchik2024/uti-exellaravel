<?php

namespace App\Http\Controllers;

class MediaController extends Controller
{
    public function show(string $path)
    {
        abort_unless(auth()->check(), 403);

        $full = storage_path("app/private/{$path}");
        abort_unless(is_file($full), 404);

        return response()->file($full);
    }
}