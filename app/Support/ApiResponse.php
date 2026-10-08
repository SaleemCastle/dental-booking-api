<?php

namespace App\Support;

use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class ApiResponse
{
    public static function success(
        mixed $data = null,
        string $message = 'OK',
        int $statusCode = 200,
        ?array $meta = null,
        ?Request $request = null
    ): JsonResponse {
        $request ??= request();

        if ($data instanceof LengthAwarePaginator) {
            $meta = array_merge(self::paginationMeta($data), $meta ?? []);
            $data = $data->items();
        } elseif ($data instanceof Paginator) {
            $meta = array_merge(self::simplePaginationMeta($data), $meta ?? []);
            $data = $data->items();
        }

        return response()->json(self::envelope(
            success: true,
            statusCode: $statusCode,
            message: $message,
            code: null,
            data: self::resolveData($data, $request),
            errors: null,
            meta: $meta,
            request: $request,
        ), $statusCode);
    }

    public static function error(
        string $message,
        int $statusCode,
        ?string $code = null,
        ?array $errors = null,
        ?array $meta = null,
        ?Request $request = null
    ): JsonResponse {
        $request ??= request();

        return response()->json(self::envelope(
            success: false,
            statusCode: $statusCode,
            message: $message,
            code: $code,
            data: null,
            errors: $errors,
            meta: $meta,
            request: $request,
        ), $statusCode);
    }

    private static function envelope(
        bool $success,
        int $statusCode,
        string $message,
        ?string $code,
        mixed $data,
        ?array $errors,
        ?array $meta,
        Request $request
    ): array {
        return [
            'success' => $success,
            'statusCode' => $statusCode,
            'message' => $message,
            'code' => $code,
            'data' => $data,
            'errors' => $errors,
            'meta' => $meta,
            'requestId' => $request->attributes->get('request_id'),
        ];
    }

    private static function resolveData(mixed $data, Request $request): mixed
    {
        if ($data instanceof JsonResource) {
            return $data->resolve($request);
        }

        if ($data instanceof Collection) {
            return $data->map(fn (mixed $item) => self::resolveData($item, $request))->all();
        }

        if (is_array($data)) {
            return collect($data)
                ->map(fn (mixed $value) => self::resolveData($value, $request))
                ->all();
        }

        return $data;
    }

    private static function paginationMeta(LengthAwarePaginator $paginator): array
    {
        return [
            'pagination' => [
                'currentPage' => $paginator->currentPage(),
                'perPage' => $paginator->perPage(),
                'total' => $paginator->total(),
                'lastPage' => $paginator->lastPage(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
                'hasMorePages' => $paginator->hasMorePages(),
            ],
        ];
    }

    private static function simplePaginationMeta(Paginator $paginator): array
    {
        return [
            'pagination' => [
                'currentPage' => $paginator->currentPage(),
                'perPage' => $paginator->perPage(),
                'hasMorePages' => $paginator->hasMorePages(),
            ],
        ];
    }
}
