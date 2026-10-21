<?php

namespace App\Services\Media;

use App\Enums\MediaProviderType;
use App\Exceptions\MediaException;
use App\Models\Media;
use App\Models\Store;
use App\Models\StoreSetting;
use App\Models\ThemeConfiguration;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Moves stored images from one media provider to another (for example Cloudinary to DigitalOcean Spaces).
 *
 * Each image is copied first, then its record is repointed together with every place its address was
 * copied to (store logo and favicon, the theme's hero image, the SEO share image). The old file is only
 * removed afterwards, and only when asked, so a failure part-way leaves the image working where it was
 * and the move can simply be run again.
 */
class MediaMigrationService
{
    public function __construct(private readonly MediaService $media) {}

    /**
     * @throws MediaException when the image cannot be fetched or stored; the record is left untouched
     */
    public function move(Media $media, MediaProviderType $to, bool $deleteSource = false): Media
    {
        if ($media->provider === $to) {
            return $media;
        }

        $source = $this->media->provider($media->provider);
        $target = $this->media->provider($to);
        $oldUrl = $media->url;
        $oldPath = $media->path;

        $temp = $this->download($oldUrl);

        try {
            $file = new UploadedFile($temp, basename($oldPath), $media->mime_type, null, true);
            $stored = $target->upload($file, preg_replace('/\.[A-Za-z0-9]+$/', '', $oldPath) ?? $oldPath);
        } finally {
            @unlink($temp);
        }

        try {
            DB::transaction(function () use ($media, $to, $stored, $oldUrl): void {
                $media->forceFill([
                    'provider' => $to, 'disk' => $stored->disk, 'path' => $stored->path, 'url' => $stored->url,
                    'mime_type' => $stored->mimeType, 'size' => $stored->size, 'width' => $stored->width, 'height' => $stored->height,
                ])->save();

                $this->rewriteAddresses($media->tenant_id, $oldUrl, $stored->url);
            });
        } catch (Throwable $e) {
            $target->delete($stored->path);

            throw $e;
        }

        if ($deleteSource) {
            try {
                $source->delete($oldPath);
            } catch (Throwable $e) {
                report($e);
            }
        }

        return $media;
    }

    /**
     * Replace the old address wherever a store has saved it as plain text.
     */
    private function rewriteAddresses(string $tenantId, string $old, string $new): void
    {
        Store::withoutTenantScope()->where('tenant_id', $tenantId)->where('logo', $old)->update(['logo' => $new]);
        Store::withoutTenantScope()->where('tenant_id', $tenantId)->where('favicon', $old)->update(['favicon' => $new]);

        foreach ([ThemeConfiguration::withoutTenantScope()->where('tenant_id', $tenantId)->get(['id', 'tenant_id', 'config']), StoreSetting::withoutTenantScope()->where('tenant_id', $tenantId)->get(['id', 'tenant_id', 'settings'])] as $rows) {
            foreach ($rows as $row) {
                $attribute = $row instanceof ThemeConfiguration ? 'config' : 'settings';
                $value = $row->{$attribute};
                $changed = false;

                array_walk_recursive($value, function (&$item) use ($old, $new, &$changed): void {
                    if ($item === $old) {
                        $item = $new;
                        $changed = true;
                    }
                });

                if ($changed) {
                    $row->forceFill([$attribute => $value])->save();
                }
            }
        }
    }

    private function download(string $url): string
    {
        $response = Http::timeout(60)->get($url);

        if ($response->failed() || $response->body() === '') {
            throw new MediaException('Could not download the image from its current provider.');
        }

        $path = tempnam(sys_get_temp_dir(), 'media-move-');
        file_put_contents($path, $response->body());

        return $path;
    }
}
