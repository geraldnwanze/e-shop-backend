<?php

namespace App\Http\Requests\Media;

class UploadBrandingRequest extends UploadMediaRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $favicon = $this->routeIs('*.favicon.*');

        return [
            'file' => $this->fileRules(
                $favicon ? config('media.favicon_extensions') : config('media.allowed_extensions'),
                $favicon ? config('media.favicon_mimes') : config('media.allowed_mimes'),
            ),
        ];
    }
}
