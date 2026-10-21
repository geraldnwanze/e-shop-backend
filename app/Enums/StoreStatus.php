<?php

namespace App\Enums;

enum StoreStatus: string
{
    case Draft = 'draft';
    case Active = 'active';
    case Inactive = 'inactive';
}
