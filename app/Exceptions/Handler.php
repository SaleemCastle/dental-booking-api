<?php

namespace App\Exceptions;

use App\Support\ApiResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

class Handler extends ExceptionHandler
{
    /**
     * The list of the inputs that are never flashed to the session on validation exceptions.
     *
     * @var array<int, string>
     */
    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
    ];

    /**
     * Register the exception handling callbacks for the application.
     */
    public function register(): void
    {
        $this->reportable(function (Throwable $e) {
            //
        });
    }

    public function render($request, Throwable $e)
    {
        if (! $this->shouldReturnApiResponse($request)) {
            return parent::render($request, $e);
        }

        if ($e instanceof ValidationException) {
            return ApiResponse::error(
                message: 'The given data was invalid.',
                statusCode: 422,
                code: 'VALIDATION_ERROR',
                errors: $e->errors(),
                request: $request,
            );
        }

        if ($e instanceof AuthenticationException) {
            return ApiResponse::error(
                message: 'Unauthenticated.',
                statusCode: 401,
                code: 'UNAUTHENTICATED',
                request: $request,
            );
        }

        if ($e instanceof AuthorizationException) {
            return ApiResponse::error(
                message: 'This action is unauthorized.',
                statusCode: 403,
                code: 'FORBIDDEN',
                request: $request,
            );
        }

        if ($e instanceof ModelNotFoundException || $e instanceof NotFoundHttpException) {
            return ApiResponse::error(
                message: 'Resource not found.',
                statusCode: 404,
                code: 'NOT_FOUND',
                request: $request,
            );
        }

        if ($e instanceof MethodNotAllowedHttpException) {
            return ApiResponse::error(
                message: 'Method not allowed.',
                statusCode: 405,
                code: 'METHOD_NOT_ALLOWED',
                request: $request,
            );
        }

        if ($e instanceof TokenMismatchException) {
            return ApiResponse::error(
                message: 'CSRF token mismatch.',
                statusCode: 419,
                code: 'CSRF_TOKEN_MISMATCH',
                request: $request,
            );
        }

        if ($e instanceof HttpExceptionInterface) {
            $statusCode = $e->getStatusCode();

            return ApiResponse::error(
                message: $this->httpMessage($statusCode),
                statusCode: $statusCode,
                code: $this->httpCode($statusCode),
                request: $request,
            );
        }

        Log::error('Unhandled API exception.', [
            'request_id' => $request->attributes->get('request_id'),
            'exception' => $e,
        ]);

        return ApiResponse::error(
            message: 'An unexpected error occurred.',
            statusCode: 500,
            code: 'SERVER_ERROR',
            request: $request,
        );
    }

    protected function context(): array
    {
        return array_merge(parent::context(), [
            'request_id' => request()?->attributes->get('request_id'),
        ]);
    }

    private function shouldReturnApiResponse(Request $request): bool
    {
        return $request->expectsJson()
            || $request->is('api/*')
            || $request->is('login')
            || $request->is('logout')
            || $request->is('register')
            || $request->is('sanctum/csrf-cookie');
    }

    private function httpMessage(int $statusCode): string
    {
        return match ($statusCode) {
            400 => 'Bad request.',
            401 => 'Unauthenticated.',
            403 => 'This action is unauthorized.',
            404 => 'Resource not found.',
            409 => 'Conflict.',
            419 => 'CSRF token mismatch.',
            422 => 'The given data was invalid.',
            429 => 'Too many requests.',
            default => $statusCode >= 500 ? 'An unexpected error occurred.' : 'Request failed.',
        };
    }

    private function httpCode(int $statusCode): string
    {
        return match ($statusCode) {
            400 => 'BAD_REQUEST',
            401 => 'UNAUTHENTICATED',
            403 => 'FORBIDDEN',
            404 => 'NOT_FOUND',
            409 => 'CONFLICT',
            419 => 'CSRF_TOKEN_MISMATCH',
            422 => 'VALIDATION_ERROR',
            429 => 'TOO_MANY_REQUESTS',
            default => $statusCode >= 500 ? 'SERVER_ERROR' : 'HTTP_ERROR',
        };
    }
}
