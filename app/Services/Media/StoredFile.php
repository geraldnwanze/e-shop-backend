<?php

namespace App\Services\Media;

final readonly class StoredFile
{
    public function __construct(
        public string $path,
        public string $url,
        public string $mimeType,
        public int $size,
        public ?int $width = null,
        public ?int $height = null,
        public ?string $disk = null,
    ) {}
}
