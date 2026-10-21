<?php

namespace App\Services\Media;

use App\Enums\MediaProviderType;
use App\Models\Media;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The only entry point for media in the application. Nothing else talks to
 * Cloudinary or DigitalOcean directly.
 */
class MediaService
{
    public function __construct(private readonly TenantContext $context) {}

    public function upload(UploadedFile $file, string $folder = 'uploads', ?string $alt = null): Media
    {
        $tenant = $this->context->requireTenant();
        $provider = $this->provider(MediaProviderType::from(config('media.driver')));

        $stored = $provider->upload($file, $this->buildPath($tenant->id, $folder));

        try {
            return Media::create([
                'provider' => $provider->type(),
                'disk' => $stored->disk,
                'folder' => $folder,
                'path' => $stored->path,
                'url' => $stored->url,
                'mime_type' => $stored->mimeType,
                'size' => $stored->size,
                'width' => $stored->width,
                'height' => $stored->height,
                'alt' => $alt,
            ]);
        } catch (\Throwable $e) {
            // Do not orphan the remote file if the metadata row cannot be saved.
            $provider->delete($stored->path);

            throw $e;
        }
    }

    public function delete(Media $media): void
    {
        $this->provider($media->provider)->delete($media->path);
        $media->delete();
    }

    /**
     * Upload the new file, repoint the row, then remove the old remote file.
     */
    public function replace(Media $media, UploadedFile $file): Media
    {
        $tenant = $this->context->requireTenant();
        $provider = $this->provider($media->provider);
        $oldPath = $media->path;

        $stored = $provider->upload($file, $this->buildPath($tenant->id, $media->folder));

        try {
            DB::transaction(fn () => $media->update([
                'disk' => $stored->disk,
                'path' => $stored->path,
                'url' => $stored->url,
                'mime_type' => $stored->mimeType,
                'size' => $stored->size,
                'width' => $stored->width,
                'height' => $stored->height,
            ]));
        } catch (\Throwable $e) {
            $provider->delete($stored->path);

            throw $e;
        }

        $provider->delete($oldPath);

        return $media->refresh();
    }

    /**
     * @param  array{width?: int, height?: int, quality?: int}  $options
     */
    public function getUrl(Media $media, array $options = []): string
    {
        return $options === [] ? $media->url : $this->provider($media->provider)->url($media->path, $options);
    }

    public function provider(MediaProviderType $type): MediaProvider
    {
        return match ($type) {
            MediaProviderType::Cloudinary => new CloudinaryMediaProvider(config('media.drivers.cloudinary')),
            MediaProviderType::Spaces => new DigitalOceanMediaProvider(config('media.drivers.spaces')),
        };
    }

    private function buildPath(string $tenantUuid, string $folder): string
    {
        return "tenants/{$tenantUuid}/".Str::slug($folder).'/'.Str::uuid();
    }
}
