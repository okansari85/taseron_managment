<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class ImageService
{
    private const DISK = 'public';

    public function upload(UploadedFile $file, string $directory): string
    {
        $path = $file->store($directory, self::DISK);

        if (! $path) {
            throw new RuntimeException('Görsel yüklenemedi.');
        }

        return $path;
    }

    public function replace(UploadedFile $file, string $directory, ?string $oldPath = null): string
    {
        $path = $this->upload($file, $directory);

        if ($oldPath && $oldPath !== $path) {
            $this->delete($oldPath);
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
