<?php

namespace App\Services\Reviews;

use App\Enums\ApiErrorCodeEnum;
use App\Exceptions\BaseApiException;
use App\Models\Customer;
use App\Models\ProductReview;
use App\Models\ReviewPhoto;
use App\Models\Tenant;
use App\Services\Media\MediaService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Photos on reviews. Every upload is decoded and re-encoded here, which strips hidden data (such as
 * the GPS position a phone embeds), shrinks it, and rejects anything that is not really an image.
 */
class ReviewPhotoService
{
    /** Longest side kept, in pixels. Review photos do not need to be larger. */
    private const MAX_SIDE = 1600;

    public const FOLDER = 'review-photos';

    public function __construct(private readonly MediaService $media, private readonly ReviewService $reviews) {}

    public function upload(Customer $customer, UploadedFile $file): ReviewPhoto
    {
        if (! $this->reviews->hasPurchased($customer)) {
            throw new BaseApiException(ApiErrorCodeEnum::Forbidden, 'Only customers who have bought something can add photos.');
        }

        $waiting = ReviewPhoto::query()->where('customer_id', $customer->getKey())->whereNull('product_review_id')->count();

        if ($waiting >= ReviewPhoto::MAX_UNATTACHED) {
            throw BaseApiException::conflict('You have several photos waiting to be used. Add them to a review or remove some first.');
        }

        $clean = $this->reencode($file);

        try {
            $media = $this->media->upload($clean, self::FOLDER);
        } finally {
            @unlink($clean->getRealPath());
        }

        $photo = new ReviewPhoto;
        $photo->customer_id = $customer->getKey();
        $photo->media_id = $media->getKey();
        $photo->save();

        return $photo->load('media');
    }

    /**
     * Make the review's photos exactly this list (in order): attach the new ones, drop the others.
     *
     * @param  list<string>  $photoIds
     */
    public function sync(ProductReview $review, Customer $customer, array $photoIds): void
    {
        $photoIds = array_values(array_unique($photoIds));

        if (count($photoIds) > ReviewPhoto::MAX_PER_REVIEW) {
            throw ValidationException::withMessages(['photo_ids' => ['You can add up to '.ReviewPhoto::MAX_PER_REVIEW.' photos.']]);
        }

        $usable = ReviewPhoto::query()->where('customer_id', $customer->getKey())
            ->whereIn('id', $photoIds)
            ->where(fn ($q) => $q->whereNull('product_review_id')->orWhere('product_review_id', $review->getKey()))
            ->get()->keyBy('id');

        if ($usable->count() !== count($photoIds)) {
            throw ValidationException::withMessages(['photo_ids' => ['One of the photos is no longer available. Please add it again.']]);
        }

        DB::transaction(function () use ($review, $photoIds, $usable): void {
            ReviewPhoto::query()->where('product_review_id', $review->getKey())->whereNotIn('id', $photoIds)->get()->each(fn (ReviewPhoto $p) => $this->delete($p));

            foreach ($photoIds as $position => $id) {
                $photo = $usable[$id];
                $photo->product_review_id = $review->getKey();
                $photo->position = $position;
                $photo->save();
            }
        });
    }

    /**
     * Remove a photo and its stored file.
     */
    public function delete(ReviewPhoto $photo): void
    {
        $media = $photo->media;
        $photo->delete();

        if ($media !== null) {
            $this->media->delete($media);
        }
    }

    /**
     * Uploads that never made it onto a review.
     */
    public function pruneOrphans(int $hours = 24): int
    {
        $stale = ReviewPhoto::withoutTenantScope()->whereNull('product_review_id')->where('created_at', '<', now()->subHours($hours))->get();
        $context = app(TenantContext::class);
        $count = 0;

        foreach ($stale->groupBy('tenant_id') as $tenantId => $photos) {
            $tenant = Tenant::query()->find($tenantId);

            // Deleting a file needs the store's own media record, so enter the store first.
            $tenant === null
                ? $photos->each(fn (ReviewPhoto $p) => $p->delete())
                : $context->runAs($tenant, fn () => $photos->each(fn (ReviewPhoto $p) => $this->delete($p)));

            $count += $photos->count();
        }

        return $count;
    }

    /**
     * Decode the upload and write a fresh JPEG: orientation applied, metadata gone, long side capped.
     */
    private function reencode(UploadedFile $file): UploadedFile
    {
        $data = @file_get_contents($file->getRealPath());
        $source = $data === false ? false : @imagecreatefromstring($data);

        if ($source === false) {
            throw ValidationException::withMessages(['photo' => ['That file could not be read as an image.']]);
        }

        $source = $this->applyOrientation($source, $data);
        [$width, $height] = [imagesx($source), imagesy($source)];
        $scale = min(1, self::MAX_SIDE / max($width, $height));
        $targetW = max(1, (int) round($width * $scale));
        $targetH = max(1, (int) round($height * $scale));

        $canvas = imagecreatetruecolor($targetW, $targetH);
        imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
        imagecopyresampled($canvas, $source, 0, 0, 0, 0, $targetW, $targetH, $width, $height);

        $path = tempnam(sys_get_temp_dir(), 'rvw').'.jpg';
        imagejpeg($canvas, $path, 82);

        return new UploadedFile($path, 'photo.jpg', 'image/jpeg', null, true);
    }

    /**
     * Phones store "rotate me" in the metadata we are about to drop, so apply it to the pixels first.
     */
    private function applyOrientation(\GdImage $image, string $data): \GdImage
    {
        if (! function_exists('exif_read_data')) {
            return $image;
        }

        $exif = @exif_read_data('data://image/jpeg;base64,'.base64_encode($data));
        $rotated = match ((int) ($exif['Orientation'] ?? 1)) {
            3 => imagerotate($image, 180, 0),
            6 => imagerotate($image, -90, 0),
            8 => imagerotate($image, 90, 0),
            default => $image,
        };

        return $rotated instanceof \GdImage ? $rotated : $image;
    }
}
