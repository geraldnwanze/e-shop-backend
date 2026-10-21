<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\ApiErrorCodeEnum;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\PlanRequest;
use App\Http\Resources\PlanResource;
use App\Models\Plan;
use App\Support\ApiResponse;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;

#[Group('Platform admin: Plans & subscriptions', weight: 42)]
class PlanController extends Controller
{
    public function index(): JsonResponse
    {
        return ApiResponse::success(PlanResource::collection(Plan::query()->withCount('subscriptions')->orderBy('position')->orderBy('id')->get()));
    }

    public function store(PlanRequest $request): JsonResponse
    {
        $plan = Plan::create($request->validated());

        return ApiResponse::created(PlanResource::make($plan->loadCount('subscriptions')), 'Plan created.');
    }

    public function update(PlanRequest $request, Plan $plan): JsonResponse
    {
        $plan->update($request->validated());

        return ApiResponse::success(PlanResource::make($plan->loadCount('subscriptions')), 'Plan updated.');
    }

    public function destroy(Plan $plan): JsonResponse
    {
        if ($plan->subscriptions()->exists()) {
            return ApiResponse::error(ApiErrorCodeEnum::Conflict, 'This plan has subscribers. Deactivate it instead of deleting it.');
        }

        $plan->delete();

        return ApiResponse::noContent('Plan deleted.');
    }
}
