<?php

namespace App\Support;

use App\Models\Worker;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

final class Scans
{
    public static function storeScan(UploadedFile $file, Worker $worker): array
    {
        $ext = strtolower($file->getClientOriginalExtension() ?: $file->extension() ?: 'bin');
        $path = $file->storeAs('scans/'.$worker->organization_id.'/'.$worker->id, Str::uuid().'.'.$ext, 'local');

        return [
            'scan_path' => $path,
            'scan_name' => mb_substr($file->getClientOriginalName() ?: 'scan.'.$ext, 0, 200),
            'scan_mime' => $file->getMimeType() ?: 'application/octet-stream',
            'scan_size' => $file->getSize(),
        ];
    }

    public static function storePhoto(UploadedFile $file, Worker $worker): string
    {
        $ext = strtolower($file->extension() ?: 'jpg');

        return $file->storeAs('photos/'.$worker->organization_id, Str::uuid().'.'.$ext, 'local');
    }

    public static function delete(?string $path): void
    {
        if ($path && Storage::disk('local')->exists($path)) {
            Storage::disk('local')->delete($path);
        }
    }

    public static function extension(?string $path, ?string $mime): string
    {
        $ext = $path ? strtolower(pathinfo($path, PATHINFO_EXTENSION)) : '';
        if ($ext !== '') {
            return $ext;
        }

        return match ($mime) {
            'application/pdf' => 'pdf',
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => 'jpg',
        };
    }
}
