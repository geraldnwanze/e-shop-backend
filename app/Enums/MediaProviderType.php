<?php

namespace App\Enums;

enum MediaProviderType: string
{
    case Cloudinary = 'cloudinary';
    case Spaces = 'spaces';
}
