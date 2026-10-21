<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\AuditLogResource;
use App\Models\AuditLog;
use App\Support\ApiResponse;
use App\Support\CsvSafe;
use App\Support\Like;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The audit trail. Reachable only through the platform-admin route group; stores never see it.
 */
#[Group('Platform admin: Audit log', weight: 45)]
class AuditLogController extends Controller
{
    private const EXPORT_LIMIT = 50000;

    public function index(Request $request): JsonResponse
    {
        $logs = $this->filtered($request)->paginate($this->filters($request)['per_page'] ?? 25);

        return ApiResponse::success(AuditLogResource::collection($logs));
    }

    public function show(AuditLog $auditLog): JsonResponse
    {
        return ApiResponse::success(AuditLogResource::make($auditLog));
    }

    /**
     * The kinds of action recorded so far, for the filter menu.
     */
    public function actions(): JsonResponse
    {
        return ApiResponse::success(AuditLog::query()->select('action')->distinct()->orderBy('action')->limit(300)->pluck('action'));
    }

    /**
     * The filtered trail as a spreadsheet (newest first, at most 50,000 rows).
     */
    public function export(Request $request): StreamedResponse
    {
        $query = $this->filtered($request)->limit(self::EXPORT_LIMIT);

        return response()->streamDownload(function () use ($query): void {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['When (UTC)', 'Action', 'Store', 'Actor type', 'Actor', 'Record type', 'Record', 'Changes', 'IP address']);

            $query->get()->each(function (AuditLog $log) use ($out): void {
                fputcsv($out, array_map(CsvSafe::cell(...), [
                    $log->created_at?->utc()->format('Y-m-d H:i:s') ?? '',
                    $log->action,
                    $log->tenant_label ?? '',
                    $log->actor_type,
                    $log->actor_label ?? '',
                    $log->subject_type ?? '',
                    $log->subject_label ?? '',
                    $log->changes ? (string) json_encode($log->changes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '',
                    (string) ($log->meta['ip'] ?? ''),
                ]));
            });

            fclose($out);
        }, 'audit-log-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store']);
    }

    /**
     * @return Builder<AuditLog>
     */
    private function filtered(Request $request): Builder
    {
        $f = $this->filters($request);

        return AuditLog::query()
            ->when($f['tenant'] ?? null, fn ($q, $v) => $q->where('tenant_id', $v))
            ->when($f['actor'] ?? null, fn ($q, $v) => $q->where('actor_id', $v))
            ->when($f['actor_type'] ?? null, fn ($q, $v) => $q->where('actor_type', $v))
            ->when($f['action'] ?? null, fn ($q, $v) => str_ends_with($v, '.') ? $q->whereLike('action', Like::contains($v)) : $q->where('action', $v))
            ->when($f['subject_id'] ?? null, fn ($q, $v) => $q->where('subject_id', $v))
            ->when($f['from'] ?? null, fn ($q, $v) => $q->where('created_at', '>=', $v))
            ->when($f['to'] ?? null, fn ($q, $v) => $q->where('created_at', '<=', $v.' 23:59:59'))
            ->when($f['search'] ?? null, fn ($q, $term) => $q->where(fn ($q) => $q
                ->whereLike('actor_label', Like::contains($term))
                ->orWhereLike('subject_label', Like::contains($term))
                ->orWhereLike('tenant_label', Like::contains($term))
                ->orWhereLike('action', Like::contains($term))))
            ->orderByDesc('created_at')->orderByDesc('id');
    }

    /**
     * @return array<string, mixed>
     */
    private function filters(Request $request): array
    {
        return $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'tenant' => ['nullable', 'uuid'],
            'actor' => ['nullable', 'uuid'],
            'actor_type' => ['nullable', Rule::in(['platform_admin', 'merchant', 'customer', 'system', 'guest'])],
            'action' => ['nullable', 'string', 'max:80', 'regex:/^[a-z_.]+$/'],
            'subject_id' => ['nullable', 'uuid'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
    }
}
