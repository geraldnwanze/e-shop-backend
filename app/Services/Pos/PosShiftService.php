<?php

namespace App\Services\Pos;

use App\Enums\OrderStatus;
use App\Exceptions\BaseApiException;
use App\Models\Order;
use App\Models\PosShift;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

/**
 * Register shifts and the figures reported from them. A shift's cash expectation is the opening
 * float plus the cash taken for sales that were not voided (change is handed back, so a cash sale
 * adds exactly its total to the drawer).
 */
class PosShiftService
{
    public function current(User $user): ?PosShift
    {
        return PosShift::query()->where('user_id', $user->getKey())->whereNull('closed_at')->latest('opened_at')->first();
    }

    public function open(User $user, int $openingFloat): PosShift
    {
        return DB::transaction(function () use ($user, $openingFloat): PosShift {
            // Lock the user's row so two taps on "Start shift" cannot open two shifts.
            User::query()->whereKey($user->getKey())->lockForUpdate()->first();

            if ($this->current($user) !== null) {
                throw BaseApiException::conflict('You already have a shift open. Close it before starting another.');
            }

            $shift = new PosShift(['opening_float' => $openingFloat, 'opened_at' => now()]);
            $shift->user_id = $user->getKey();
            $shift->save();

            return $shift;
        });
    }

    public function close(PosShift $shift, int $countedCash, ?string $notes): PosShift
    {
        return DB::transaction(function () use ($shift, $countedCash, $notes): PosShift {
            $locked = PosShift::query()->whereKey($shift->getKey())->lockForUpdate()->firstOrFail();

            if (! $locked->isOpen()) {
                throw BaseApiException::conflict('This shift is already closed.');
            }

            $locked->forceFill([
                'closed_at' => now(),
                'expected_cash' => $this->expectedCash($locked),
                'counted_cash' => $countedCash,
                'notes' => $notes,
            ])->save();

            return $locked;
        });
    }

    public function expectedCash(PosShift $shift): int
    {
        return $shift->opening_float + (int) $this->sales($shift->orders())->where('payment_method', 'cash')->sum('total');
    }

    /**
     * Figures for one shift. The expected cash is frozen when the shift closes.
     *
     * @return array{salesCount: int, grossSales: int, discounts: int, voidedCount: int, voidedTotal: int, averageSale: int, byMethod: array<string, array{count: int, total: int}>, openingFloat: int, expectedCash: int, countedCash: ?int, variance: ?int}
     */
    public function summary(PosShift $shift): array
    {
        $totals = $this->totals($shift->orders()->getQuery());
        $expected = $shift->expected_cash ?? $this->expectedCash($shift);

        return $totals + [
            'openingFloat' => $shift->opening_float,
            'expectedCash' => $expected,
            'countedCash' => $shift->counted_cash,
            'variance' => $shift->counted_cash !== null ? $shift->counted_cash - $expected : null,
        ];
    }

    /**
     * One row per cashier who rang up in-store sales in the period.
     *
     * @return list<array<string, mixed>>
     */
    public function cashierReport(\DateTimeInterface $from, \DateTimeInterface $to, ?string $onlyUserId): array
    {
        $orders = fn () => Order::query()->where('channel', 'pos')->whereNotNull('cashier_id')
            ->whereBetween('placed_at', [$from, $to])
            ->when($onlyUserId, fn ($q, $id) => $q->where('cashier_id', $id));

        $cashierIds = $orders()->distinct()->pluck('cashier_id');
        $names = User::query()->whereIn('id', $cashierIds)->pluck('name', 'id');

        $shifts = PosShift::query()->whereBetween('opened_at', [$from, $to])->whereIn('user_id', $cashierIds)->get()->groupBy('user_id');

        return $cashierIds->map(function (string $id) use ($orders, $names, $shifts): array {
            $closed = ($shifts[$id] ?? collect())->whereNotNull('closed_at');

            return ['userId' => $id, 'name' => $names[$id] ?? 'Former staff member'] + $this->totals($orders()->where('cashier_id', $id)) + [
                'shifts' => ($shifts[$id] ?? collect())->count(),
                'cashVariance' => $closed->isEmpty() ? null : (int) $closed->sum(fn (PosShift $s) => $s->counted_cash - $s->expected_cash),
            ];
        })->sortByDesc('grossSales')->values()->all();
    }

    /**
     * @param  Builder<Order>  $orders
     * @return array{salesCount: int, grossSales: int, discounts: int, voidedCount: int, voidedTotal: int, averageSale: int, byMethod: array<string, array{count: int, total: int}>}
     */
    private function totals(Builder $orders): array
    {
        $sales = $this->sales(clone $orders);
        $count = (clone $sales)->count();
        $gross = (int) (clone $sales)->sum('total');
        $voided = (clone $orders)->where('status', OrderStatus::Cancelled->value);

        $byMethod = ['cash' => ['count' => 0, 'total' => 0], 'transfer' => ['count' => 0, 'total' => 0], 'card' => ['count' => 0, 'total' => 0]];

        foreach ((clone $sales)->selectRaw('payment_method, count(*) as sales, sum(total) as total')->groupBy('payment_method')->get() as $row) {
            if (isset($byMethod[$row->payment_method])) {
                $byMethod[$row->payment_method] = ['count' => (int) $row->sales, 'total' => (int) $row->total];
            }
        }

        return [
            'salesCount' => $count,
            'grossSales' => $gross,
            'discounts' => (int) (clone $sales)->sum('discount_total'),
            'voidedCount' => (clone $voided)->count(),
            'voidedTotal' => (int) (clone $voided)->sum('total'),
            'averageSale' => $count > 0 ? intdiv($gross, $count) : 0,
            'byMethod' => $byMethod,
        ];
    }

    /**
     * @param  Builder<Order>|HasMany<Order, PosShift>  $orders
     * @return Builder<Order>
     */
    private function sales($orders): Builder
    {
        $query = $orders instanceof Builder ? $orders : $orders->getQuery();

        return $query->where('channel', 'pos')->where('status', '!=', OrderStatus::Cancelled->value);
    }
}
