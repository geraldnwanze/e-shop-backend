<?php

namespace App\Services\Media;

use App\Enums\MediaProviderType;
use App\Exceptions\MediaException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Cloudinary via its signed REST API (no SDK dependency).
 * `path` is the Cloudinary public_id.
 */
class CloudinaryMediaProvider implements MediaProvider
{
    /**
     * @param  array{cloud_name: ?string, api_key: ?string, api_secret: ?string}  $config
     */
    public function __construct(private readonly array $config) {}

    public function type(): MediaProviderType
    {
        return MediaProviderType::Cloudinary;
    }

    public function upload(UploadedFile $file, string $path): StoredFile
    {
        $params = ['public_id' => $path, 'timestamp' => time()];

        $response = Http::timeout(30)
            ->attach('file', $file->getContent(), $file->getClientOriginalName())
            ->post($this->endpoint('image/upload'), $this->signed($params));

        if ($response->failed()) {
            Log::error('Cloudinary upload failed', ['status' => $response->status(), 'error' => $response->json('error.message')]);

            throw new MediaException('Image upload failed.');
        }

        return new StoredFile(
            path: $response->json('public_id'),
            url: $response->json('secure_url'),
            mimeType: $file->getMimeType() ?? 'application/octet-stream',
            size: (int) $response->json('bytes', $file->getSize()),
            width: $response->json('width'),
            height: $response->json('height'),
        );
    }

    public function delete(string $path): void
    {
        $response = Http::timeout(30)->asForm()->post(
            $this->endpoint('image/destroy'),
            $this->signed(['public_id' => $path, 'timestamp' => time(), 'invalidate' => 'true']),
        );

        // "not found" means it is already gone; anything else must surface.
        if ($response->failed() || ! in_array($response->json('result'), ['ok', 'not found'], true)) {
            Log::error('Cloudinary delete failed', ['status' => $response->status(), 'path' => $path]);

            throw new MediaException('Image deletion failed.');
        }
    }

    public function url(string $path, array $options = []): string
    {
        $transform = ['f_auto', 'q_'.($options['quality'] ?? 'auto')];

        if (isset($options['width'])) {
            $transform[] = 'w_'.(int) $options['width'];
        }

        if (isset($options['height'])) {
            $transform[] = 'h_'.(int) $options['height'];
        }

        if (isset($options['width']) || isset($options['height'])) {
            $transform[] = 'c_limit';
        }

        return sprintf(
            'https://res.cloudinary.com/%s/image/upload/%s/%s',
            $this->config['cloud_name'],
            implode(',', $transform),
            $path,
        );
    }

    private function endpoint(string $resource): string
    {
        return sprintf('https://api.cloudinary.com/v1_1/%s/%s', $this->config['cloud_name'], $resource);
    }

    /**
     * @param  array<string, scalar>  $params
     * @return array<string, scalar>
     */
    private function signed(array $params): array
    {
        ksort($params);
        $toSign = urldecode(http_build_query($params));

        return $params + [
            'api_key' => $this->config['api_key'],
            'signature' => sha1($toSign.$this->config['api_secret']),
        ];
    }
}
