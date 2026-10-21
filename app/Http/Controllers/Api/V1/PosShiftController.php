<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Permission;
use App\Exceptions\BaseApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Pos\CloseShiftRequest;
use App\Http\Requests\Pos\OpenShiftRequest;
use App\Http\Resources\PosShiftResource;
use App\Models\Order;
use App\Models\PosShift;
use App\Models\User;
use App\Services\Pos\PosShiftService;
use App\Support\ApiResponse;
use Carbon\CarbonImmutable;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

#[Group('Merchant: Point of sale', weight: 24)]
class PosShiftController extends Controller
{
    private const MAX_REPORT_DAYS = 366;

    public function __construct(private readonly PosShiftService $shifts) {}

    /**
     * The signed-in cashier's open shift, or an empty data object when they have not started one.
     */
    public function current(Request $request): JsonResponse
    {
        $this->authorize('create', Order::class);

        $shift = $this->shifts->current($request->user());

        return ApiResponse::success($shift ? PosShiftResource::make($shift->load('user')) : null);
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorize('create', Order::class);

        $filters = $request->validate([
            'user_id' => ['nullable', 'uuid'],
            'status' => ['nullable', 'in:open,closed'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $shifts = PosShift::query()->with('user')
            ->when(! $this->isManager($request->user()), fn ($q) => $q->where('user_id', $request->user()->getKey()))
            ->when($filters['user_id'] ?? null, fn ($q, $v) => $q->where('user_id', $v))
            ->when(($filters['status'] ?? null) === 'open', fn ($q) => $q->whereNull('closed_at'))
            ->when(($filters['status'] ?? null) === 'closed', fn ($q) => $q->whereNotNull('closed_at'))
            ->when($filters['from'] ?? null, fn ($q, $v) => $q->where('opened_at', '>=', $v))
            ->when($filters['to'] ?? null, fn ($q, $v) => $q->where('opened_at', '<=', CarbonImmutable::parse($v)->endOfDay()))
            ->latest('opened_at')->latest('id')
            ->paginate($filters['per_page'] ?? 25);

        return ApiResponse::success(PosShiftResource::collection($shifts));
    }

    public function store(OpenShiftRequest $request): JsonResponse
    {
        $this->authorize('create', Order::class);

        $shift = $this->shifts->open($request->user(), $request->validated('opening_float'));

        return ApiResponse::created(PosShiftResource::make($shift->load('user')), 'Shift started.');
    }

    public function show(Request $request, PosShift $shift): JsonResponse
    {
        $this->assertCanAccess($request->user(), $shift);

        return ApiResponse::success(PosShiftResource::make($shift->load('user')));
    }

    public function close(CloseShiftRequest $request, PosShift $shift): JsonResponse
    {
        $this->assertCanAccess($request->user(), $shift);

        $closed = $this->shifts->close($shift, $request->validated('counted_cash'), $request->validated('notes'));

        return ApiResponse::success(PosShiftResource::make($closed->load('user')), 'Shift closed.');
    }

    /**
     * Sales, voids and cash handling per cashier. Managers see everyone; other staff see only themselves.
     */
    public function cashierReport(Request $request): JsonResponse
    {
        $this->authorize('create', Order::class);

        $filters = $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date']]);

        $to = CarbonImmutable::parse($filters['to'] ?? now())->endOfDay();
        $from = CarbonImmutable::parse($filters['from'] ?? $to->subDays(6))->startOfDay();

        if ($from->greaterThan($to) || $from->diffInDays($to) > self::MAX_REPORT_DAYS) {
            throw BaseApiException::conflict('Choose a period of up to a year, with the start before the end.');
        }

        $rows = $this->shifts->cashierReport($from, $to, $this->isManager($request->user()) ? null : $request->user()->getKey());

        return ApiResponse::success(['from' => $from->toDateString(), 'to' => $to->toDateString(), 'cashiers' => $rows]);
    }

    private function isManager(User $user): bool
    {
        return $user->hasPermission(Permission::ManageStaff);
    }

    private function assertCanAccess(User $user, PosShift $shift): void
    {
        $this->authorize('create', Order::class);

        abort_unless($shift->user_id === $user->getKey() || $this->isManager($user), 404);
    }
}
