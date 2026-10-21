<?php

namespace App\Support;

use App\Models\StoreSetting;
use Illuminate\Http\Request;

/**
 * The store's "only N left" level for the current request's tenant, read once per request however
 * many products are being listed.
 */
final class StockNotice
{
    private const ATTRIBUTE = 'stock_notice_level';

    public static function threshold(Request $request): int
    {
        if (! $request->attributes->has(self::ATTRIBUTE)) {
            $stored = StoreSetting::query()->first()?->settings['inventory']['low_stock_notice'] ?? null;

            $request->attributes->set(self::ATTRIBUTE, max(0, (int) ($stored ?? config('themes.default_settings.inventory.low_stock_notice'))));
        }

        return $request->attributes->get(self::ATTRIBUTE);
    }
}
