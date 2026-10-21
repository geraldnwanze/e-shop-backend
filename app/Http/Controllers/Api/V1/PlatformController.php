<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\PlanResource;
use App\Models\Plan;
use App\Services\Platform\PlatformSettings;
use App\Support\ApiResponse;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;

#[Group('Platform (public)', weight: 2)]
class PlatformController extends Controller
{
    /**
     * Public marketing data: the plans a merchant can start on, and whether sign-ups are open.
     * Contains no tenant data; inactive plans are never exposed.
     */
    public function plans(PlatformSettings $settings): JsonResponse
    {
        return ApiResponse::success([
            'platform' => [
                'name' => $settings->get('platform.name'),
                'registrationOpen' => (bool) $settings->get('registration.enabled'),
                'supportEmail' => $settings->get('platform.support_email'),
                'trialDays' => (int) $settings->get('billing.trial_days'),
                'billingPeriods' => collect(config('billing.periods'))->map(fn (int $discount, int $months) => ['months' => $months, 'discountPercent' => $discount])->values()->all(),
            ],
            'plans' => PlanResource::collection(Plan::query()->where('is_active', true)->orderBy('position')->orderBy('id')->get()),
        ]);
    }
}
