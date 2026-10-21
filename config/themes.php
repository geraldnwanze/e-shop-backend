<?php

return [

    /*
    | Fonts a merchant may select. Storefront themes load these via next/font.
    */
    'fonts' => ['Inter', 'Poppins', 'Roboto', 'Open Sans', 'Lato', 'Montserrat', 'Playfair Display', 'Merriweather', 'DM Sans', 'Nunito'],

    'default_theme' => 'minimal',

    'default_configuration' => [
        'colors' => [
            'primary' => '#111827',
            'secondary' => '#6b7280',
            'accent' => '#f59e0b',
            'background' => '#ffffff',
            'text' => '#111827',
        ],
        'fonts' => ['heading' => 'Inter', 'body' => 'Inter'],
        'hero' => [
            'title' => 'Welcome to our store',
            'subtitle' => 'Discover our latest products',
            'image_url' => null,
            'cta_label' => 'Shop now',
            'cta_url' => '/products',
        ],
        'navigation' => [
            ['label' => 'Home', 'url' => '/'],
            ['label' => 'Products', 'url' => '/products'],
        ],
        'footer' => ['text' => null, 'links' => []],
        'social' => ['facebook' => null, 'instagram' => null, 'twitter' => null, 'tiktok' => null, 'youtube' => null],
        'homepage' => [
            'sections' => [
                ['type' => 'hero', 'enabled' => true, 'config' => []],
                ['type' => 'featured_products', 'enabled' => true, 'config' => ['limit' => 8]],
                ['type' => 'categories', 'enabled' => true, 'config' => []],
                ['type' => 'testimonials', 'enabled' => false, 'config' => []],
                ['type' => 'newsletter', 'enabled' => false, 'config' => []],
            ],
        ],
    ],

    'default_settings' => [
        'contact' => ['email' => null, 'phone' => null, 'address' => null],
        'seo' => ['title' => null, 'description' => null, 'og_image' => null],
        'checkout' => ['allow_guest_checkout' => true, 'require_phone' => false, 'shipping_flat_rate' => 0],
        'whatsapp' => ['enabled' => false, 'number' => null, 'message' => null],
        'reviews' => ['enabled' => true, 'require_approval' => false],
        'inventory' => ['low_stock_notice' => 5],
        'notifications' => [
            'customer_order_confirmation' => true,
            'customer_order_updates' => true,
            'customer_payment_receipt' => true,
            'customer_refund' => true,
            'merchant_new_order' => true,
            'merchant_payment_failed' => true,
            'merchant_low_stock' => true,
            'merchant_new_review' => true,
            'merchant_review_digest' => false,
            'merchant_campaign_failure' => true,
            'campaign_tracking' => true,
            'low_stock_threshold' => 5,
            'abandoned_cart_reminder' => false,
            'abandoned_cart_delay_hours' => 4,
            'abandoned_cart_second_reminder' => false,
            'abandoned_cart_second_delay_hours' => 24,
            'abandoned_cart_discount_percent' => 10,
            'review_request' => false,
            'review_request_delay_days' => 3,
            'merchant_email' => null,
        ],
    ],
];
