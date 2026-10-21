<?php

namespace App\Enums;

enum DomainType: string
{
    case Subdomain = 'SUBDOMAIN';
    case Custom = 'CUSTOM';
}
