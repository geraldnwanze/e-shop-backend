<?php

namespace App\Services\Media;

use App\Enums\MediaProviderType;
use App\Exceptions\MediaException;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * DigitalOcean Spaces through Laravel's S3-compatible filesystem disk.
 * `path` is the object key including extension.
 */
class DigitalOceanMediaProvider implements MediaProvider
{
    /**
     * @param  array{disk: string, cdn_url: ?string}  $config
     */
    public function __construct(private readonly array $config) {}

    public function type(): MediaProviderType
    {
        return MediaProviderType::Spaces;
    }

    public function upload(UploadedFile $file, string $path): StoredFile
    {
        $key = $path.'.'.($file->guessExtension() ?: $file->getClientOriginalExtension());
        $dimensions = @getimagesize($file->getRealPath()) ?: [null, null];

        try {
            $stored = $this->disk()->putFileAs(dirname($key), $file, basename($key), 'public');
        } catch (Throwable $e) {
            report($e);

            throw new MediaException('Image upload failed.');
        }

        if ($stored === false) {
            throw new MediaException('Image upload failed.');
        }

        return new StoredFile(
            path: $key,
            url: $this->url($key),
            mimeType: $file->getMimeType() ?? 'application/octet-stream',
            size: (int) $file->getSize(),
            width: $dimensions[0],
            height: $dimensions[1],
            disk: $this->config['disk'],
        );
    }

    public function delete(string $path): void
    {
        try {
            $this->disk()->delete($path);
        } catch (Throwable $e) {
            report($e);

            throw new MediaException('Image deletion failed.');
        }
    }

    public function url(string $path, array $options = []): string
    {
        $cdn = $this->config['cdn_url'] ?? null;

        return $cdn ? rtrim($cdn, '/').'/'.ltrim($path, '/') : $this->disk()->url($path);
    }

    private function disk(): Filesystem
    {
        return Storage::disk($this->config['disk']);
    }
}
