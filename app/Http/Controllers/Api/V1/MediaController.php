<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Media\UpdateMediaRequest;
use App\Http\Requests\Media\UploadMediaRequest;
use App\Http\Resources\MediaResource;
use App\Models\Media;
use App\Services\Media\MediaService;
use App\Services\Reviews\ReviewPhotoService;
use App\Support\ApiResponse;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

#[Group('Merchant: Media', weight: 24)]
class MediaController extends Controller
{
    public function __construct(private readonly MediaService $media) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Media::class);

        $folder = $request->validate(['folder' => ['nullable', 'string', 'max:100']])['folder'] ?? null;

        return ApiResponse::success(MediaResource::collection(
            Media::query()->where('folder', '!=', ReviewPhotoService::FOLDER)->when($folder, fn ($q) => $q->where('folder', $folder))->latest('id')->paginate(30),
        ));
    }

    public function store(UploadMediaRequest $request): JsonResponse
    {
        $this->authorize('create', Media::class);

        $media = $this->media->upload($request->file('file'), $request->validated('folder') ?? 'uploads', $request->validated('alt'));

        return ApiResponse::created(MediaResource::make($media), 'Media uploaded.');
    }

    public function update(UpdateMediaRequest $request, Media $media): JsonResponse
    {
        $this->authorize('update', $media);

        $media->update($request->validated());

        return ApiResponse::success(MediaResource::make($media), 'Media updated.');
    }

    public function replace(UploadMediaRequest $request, Media $media): JsonResponse
    {
        $this->authorize('update', $media);

        return ApiResponse::success(MediaResource::make($this->media->replace($media, $request->file('file'))), 'Media replaced.');
    }

    public function destroy(Media $media): JsonResponse
    {
        $this->authorize('delete', $media);

        $this->media->delete($media);

        return ApiResponse::noContent('Media deleted.');
    }
}
