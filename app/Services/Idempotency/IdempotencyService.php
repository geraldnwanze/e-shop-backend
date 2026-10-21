<?php

namespace App\Services\Idempotency;

use App\Enums\ApiErrorCodeEnum;
use App\Exceptions\BaseApiException;
use App\Models\IdempotencyKey;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * PostgreSQL-backed idempotency. The (tenant, scope, key) unique index is the
 * lock: only one request can create the row, so concurrent retries either wait
 * (409) or replay the stored response.
 */
class IdempotencyService
{
    private const TTL_HOURS = 24;

    private const STALE_AFTER_SECONDS = 120;

    /**
     * @return array{record: IdempotencyKey, replay: ?array{status: int, body: array<string, mixed>}}
     */
    public function begin(string $scope, string $key, string $requestHash): array
    {
        IdempotencyKey::query()->where('expires_at', '<', now())->where('scope', $scope)->where('key', $key)->delete();

        try {
            // Own (savepoint) transaction so a unique violation cannot poison an outer PostgreSQL transaction.
            $record = DB::transaction(fn () => IdempotencyKey::create([
                'scope' => $scope,
                'key' => $key,
                'request_hash' => $requestHash,
                'expires_at' => now()->addHours(self::TTL_HOURS),
            ]));

            return ['record' => $record, 'replay' => null];
        } catch (UniqueConstraintViolationException) {
            $existing = IdempotencyKey::query()->where('scope', $scope)->where('key', $key)->firstOrFail();

            if (! hash_equals($existing->request_hash, $requestHash)) {
                throw new BaseApiException(ApiErrorCodeEnum::IdempotencyKeyReused, 'This Idempotency-Key was already used with a different request.');
            }

            if ($existing->completed_at !== null) {
                return ['record' => $existing, 'replay' => ['status' => $existing->response_status, 'body' => $existing->response_body]];
            }

            if ($existing->created_at->lt(now()->subSeconds(self::STALE_AFTER_SECONDS))) {
                $existing->delete(); // abandoned by a crashed worker

                return $this->begin($scope, $key, $requestHash);
            }

            throw new BaseApiException(ApiErrorCodeEnum::RequestInProgress, 'A request with this Idempotency-Key is still being processed.');
        }
    }

    /**
     * @param  array<string, mixed>  $body
     */
    public function complete(IdempotencyKey $record, int $status, array $body): void
    {
        $record->forceFill(['response_status' => $status, 'response_body' => $body, 'completed_at' => now()])->save();
    }

    public function abort(IdempotencyKey $record): void
    {
        $record->delete();
    }
}
