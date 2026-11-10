<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Encoders\WebpEncoder;
use Intervention\Image\Laravel\Facades\Image;

/**
 * Сохранение фото: уменьшение до 1920 px, перекодирование в WebP (заодно убираются
 * метаданные, в т.ч. координаты съёмки) и миниатюра 480 px. Файлы — на закрытом диске local.
 */
class ImageStore
{
    public const MAX_SIDE = 1920;
    public const THUMB_SIDE = 480;

    /** @return array{path: string, thumb_path: string, width: int, height: int, size: int} */
    public function store(UploadedFile $file, string $dir): array
    {
        $base = Str::random(24);
        $image = Image::decode($file->getRealPath());
        $image->scaleDown(self::MAX_SIDE, self::MAX_SIDE);
        $full = (string) $image->encode(new WebpEncoder(quality: 82));
        Storage::disk('local')->put("$dir/$base.webp", $full);
        $width = $image->width();
        $height = $image->height();

        $image->scaleDown(self::THUMB_SIDE, self::THUMB_SIDE);
        Storage::disk('local')->put("$dir/{$base}_t.webp", (string) $image->encode(new WebpEncoder(quality: 75)));

        return ['path' => "$dir/$base.webp", 'thumb_path' => "$dir/{$base}_t.webp", 'width' => $width, 'height' => $height, 'size' => strlen($full)];
    }
}
