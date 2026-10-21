<?php

namespace App\Services\Themes;

use App\Models\Media;
use App\Models\Store;
use App\Services\Media\MediaService;
use Illuminate\Http\UploadedFile;

class StoreBrandingService
{
    public function __construct(private readonly MediaService $media) {}

    public function setImage(Store $store, string $field, UploadedFile $file): Store
    {
        $previous = $store->{$field};
        $media = $this->media->upload($file, 'branding', ucfirst($field).' of '.$store->name);

        $store->update([$field => $media->url]);
        $this->removeMediaFor($previous);

        return $store;
    }

    public function clearImage(Store $store, string $field): Store
    {
        $previous = $store->{$field};
        $store->update([$field => null]);
        $this->removeMediaFor($previous);

        return $store;
    }

    private function removeMediaFor(?string $url): void
    {
        if ($url === null) {
            return;
        }

        // Tenant-scoped lookup: can only ever match this tenant's own media.
        Media::query()->where('url', $url)->get()->each(fn (Media $media) => $this->media->delete($media));
    }
}
