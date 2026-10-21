<?php

namespace App\Support;

use App\Enums\ApiErrorCodeEnum;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Pagination\AbstractPaginator;

final class ApiResponse
{
    /**
     * @param  array<string, mixed>  $meta
     */
    public static function success(mixed $data = null, string $message = 'Success', int $status = 200, array $meta = []): JsonResponse
    {
        if ($data instanceof ResourceCollection && $data->resource instanceof AbstractPaginator) {
            $paginator = $data->resource;
            $meta += [
                'currentPage' => $paginator->currentPage(),
                'perPage' => $paginator->perPage(),
                'total' => method_exists($paginator, 'total') ? $paginator->total() : null,
                'lastPage' => method_exists($paginator, 'lastPage') ? $paginator->lastPage() : null,
            ];
            $data = $data->resolve();
        } elseif ($data instanceof JsonResource) {
            $data = $data->resolve();
        }

        return self::build(true, $status, $message, $data ?? (object) [], $meta);
    }

    public static function created(mixed $data = null, string $message = 'Created'): JsonResponse
    {
        return self::success($data, $message, 201);
    }

    public static function noContent(string $message = 'Success'): JsonResponse
    {
        return self::success(null, $message);
    }

    /**
     * @param  array<string, mixed>  $errors
     * @param  array<string, mixed>  $meta
     */
    public static function error(
        ApiErrorCodeEnum $code,
        string $message,
        ?int $status = null,
        array $errors = [],
        array $meta = [],
    ): JsonResponse {
        $meta['errorCode'] = $code->value;

        if ($errors !== []) {
            $meta['errors'] = $errors;
        }

        return self::build(false, $status ?? $code->defaultStatusCode(), $message, (object) [], $meta);
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private static function build(bool $status, int $statusCode, string $message, mixed $data, array $meta): JsonResponse
    {
        return response()->json([
            'status' => $status,
            'statusCode' => $statusCode,
            'message' => $message,
            'data' => $data,
            'meta' => (object) $meta,
            'requestId' => request()?->attributes->get('request_id'),
        ], $statusCode);
    }
}
