<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Media Provider
    |--------------------------------------------------------------------------
    |
    | Provider used for NEW uploads: "cloudinary" or "spaces" (DigitalOcean).
    | Each media row records the provider that stored it, so switching this
    | value never breaks existing files.
    |
    */

    'driver' => env('MEDIA_DRIVER', 'cloudinary'),

    'max_upload_kb' => (int) env('MEDIA_MAX_UPLOAD_KB', 5120),

    'allowed_mimes' => ['image/jpeg', 'image/png', 'image/webp', 'image/gif'],
    'allowed_extensions' => ['jpg', 'jpeg', 'png', 'webp', 'gif'],

    'favicon_mimes' => ['image/png', 'image/x-icon', 'image/vnd.microsoft.icon', 'image/webp'],
    'favicon_extensions' => ['png', 'ico', 'webp'],

    'max_dimension' => 8000,

    'drivers' => [
        'cloudinary' => [
            'cloud_name' => env('CLOUDINARY_CLOUD_NAME'),
            'api_key' => env('CLOUDINARY_API_KEY'),
            'api_secret' => env('CLOUDINARY_API_SECRET'),
        ],
        'spaces' => [
            // Local development can point this at the "public" disk instead of a real Space.
            'disk' => env('MEDIA_SPACES_DISK', 'spaces'),
            'cdn_url' => env('DIGITALOCEAN_SPACES_CDN_URL'),
        ],
    ],
];
