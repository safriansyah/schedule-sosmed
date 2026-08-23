<?php

namespace App\Services;

use App\Enums\MediaType;
use App\Models\Content;
use App\Models\MediaFile;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class MediaUploadService
{
    public function __construct(private readonly string $disk = 'public') {}

    /**
     * Store an uploaded file and attach it to the content.
     *
     * Dimensions are captured on upload so publishers and the aspect-ratio
     * checks never have to touch the filesystem again.
     */
    public function attach(Content $content, UploadedFile $file, ?int $position = null): MediaFile
    {
        $path = $file->store('content/'.$content->getKey(), $this->disk);

        $type = MediaType::fromMime($file->getMimeType());
        [$width, $height] = $this->dimensions($file, $type);

        return $content->media()->create([
            'type' => $type,
            'disk' => $this->disk,
            'path' => $path,
            'original_name' => $file->getClientOriginalName(),
            'mime' => $file->getMimeType(),
            'size' => $file->getSize(),
            'width' => $width,
            'height' => $height,
            'position' => $position ?? ($content->media()->max('position') + 1),
        ]);
    }

    /** Attach many files at once, preserving their order. */
    public function attachMany(Content $content, array $files): void
    {
        $position = (int) $content->media()->max('position');

        foreach ($files as $file) {
            if ($file instanceof UploadedFile) {
                $this->attach($content, $file, ++$position);
            }
        }
    }

    /** Remove a media file from both the disk and the database. */
    public function detach(MediaFile $media): void
    {
        Storage::disk($media->disk)->delete($media->path);

        $media->delete();
    }

    /**
     * Image dimensions. Videos are skipped — reading their size needs ffprobe,
     * and Reels accept a wide range of ratios anyway.
     *
     * @return array{0: ?int, 1: ?int}
     */
    private function dimensions(UploadedFile $file, MediaType $type): array
    {
        if ($type !== MediaType::Image) {
            return [null, null];
        }

        $info = @getimagesize($file->getRealPath());

        return $info === false ? [null, null] : [$info[0], $info[1]];
    }
}
