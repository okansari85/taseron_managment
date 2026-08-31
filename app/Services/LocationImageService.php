<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class LocationImageService
{
    private const DISK = 'public';
    private const DIRECTORY = 'location-images';

    public function upload(UploadedFile $file): string
    {
        $path = $file->store(self::DIRECTORY, self::DISK);

        if (! $path) {
            throw new RuntimeException('Lokasyon resmi yüklenemedi.');
        }

        return $path;
    }

    public function delete(?string $path): void
    {
        if ($path) {
            Storage::disk(self::DISK)->delete($path);
        }
    }
}
