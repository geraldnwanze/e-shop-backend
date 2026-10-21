<?php

namespace App\Http\Requests\Reviews;

use Illuminate\Foundation\Http\FormRequest;

class UploadReviewPhotoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Photos only: no GIFs or other formats. The upload is decoded and re-encoded afterwards, so what
     * is stored is always a plain JPEG.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'photo' => [
                'required', 'file',
                'max:'.config('media.max_upload_kb'),
                'extensions:jpg,jpeg,png,webp',
                'mimetypes:image/jpeg,image/png,image/webp',
                'dimensions:max_width='.config('media.max_dimension').',max_height='.config('media.max_dimension').',min_width=100,min_height=100',
            ],
        ];
    }
}
