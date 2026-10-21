<?php

namespace App\Services\Media;

use App\Enums\MediaProviderType;
use Illuminate\Http\UploadedFile;

interface MediaProvider
{
    public function type(): MediaProviderType;

    /**
     * Store the file under $path (a key without extension, e.g.
     * "tenants/{uuid}/products/{uuid}"). Providers decide how to apply an extension.
     */
    public function upload(UploadedFile $file, string $path): StoredFile;

    public function delete(string $path): void;

    /**
     * Public URL for a stored path. $options: width, height, quality (best effort per provider).
     *
     * @param  array{width?: int, height?: int, quality?: int}  $options
     */
    public function url(string $path, array $options = []): string;
}
