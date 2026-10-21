<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ThemeStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\ThemeResource;
use App\Models\Theme;
use App\Support\ApiResponse;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;

#[Group('Merchant: Store', weight: 21)]
class ThemeController extends Controller
{
    public function index(): JsonResponse
    {
        return ApiResponse::success(
            ThemeResource::collection(Theme::query()->where('status', ThemeStatus::Active)->orderBy('name')->get()),
        );
    }
}
