<?php

namespace App\Enums;

enum HomepageSectionType: string
{
    case Hero = 'hero';
    case FeaturedProducts = 'featured_products';
    case Categories = 'categories';
    case Testimonials = 'testimonials';
    case Newsletter = 'newsletter';
}
