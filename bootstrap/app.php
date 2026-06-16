<?php

use App\Http\Middleware\CheckRole;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'role' => CheckRole::class,
        ]);

        $middleware->api(prepend: [
            \Illuminate\Http\Middleware\HandleCors::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(function ($request) {
            return $request->is('api/*') || $request->expectsJson();
        });

        $isApi = fn($request) => $request->is('api/*') || $request->expectsJson();

        // 404 — registro não encontrado via Eloquent (findOrFail, etc.)
        $exceptions->render(function (
            \Illuminate\Database\Eloquent\ModelNotFoundException $e,
            $request
        ) use ($isApi) {
            if ($isApi($request)) {
                return response()->json(['message' => 'Recurso não encontrado.'], 404);
            }
        });

        // 404 — rota não encontrada
        $exceptions->render(function (
            \Symfony\Component\HttpKernel\Exception\NotFoundHttpException $e,
            $request
        ) use ($isApi) {
            if ($isApi($request)) {
                return response()->json(['message' => 'Rota não encontrada.'], 404);
            }
        });

        // 422 — falha de validação
        $exceptions->render(function (
            \Illuminate\Validation\ValidationException $e,
            $request
        ) use ($isApi) {
            if ($isApi($request)) {
                return response()->json([
                    'message' => 'Os dados informados são inválidos.',
                    'errors'  => $e->errors(),
                ], 422);
            }
        });

        // 401 — não autenticado (token ausente ou inválido)
        $exceptions->render(function (
            \Illuminate\Auth\AuthenticationException $e,
            $request
        ) use ($isApi) {
            if ($isApi($request)) {
                return response()->json([
                    'message' => 'Não autenticado. Faça login para continuar.',
                ], 401);
            }
        });

        // 429 — throttle
        $exceptions->render(function (
            \Illuminate\Http\Exceptions\ThrottleRequestsException $e,
            $request
        ) use ($isApi) {
            if ($isApi($request)) {
                return response()->json([
                    'message' => 'Muitas tentativas. Aguarde um momento e tente novamente.',
                ], 429);
            }
        });

        // Demais HttpExceptions (403, 405, etc.)
        $exceptions->render(function (
            \Symfony\Component\HttpKernel\Exception\HttpException $e,
            $request
        ) use ($isApi) {
            if ($isApi($request)) {
                $status = $e->getStatusCode();
                $message = match ($status) {
                    400 => 'Requisição inválida.',
                    403 => $e->getMessage() ?: 'Acesso não autorizado.',
                    405 => 'Método não permitido.',
                    default => $e->getMessage() ?: 'Erro na requisição.',
                };
                return response()->json(['message' => $message], $status);
            }
        });

        // 500 — qualquer outra exceção inesperada
        $exceptions->render(function (\Throwable $e, $request) use ($isApi) {
            if ($isApi($request)) {
                \Illuminate\Support\Facades\Log::error('Erro inesperado: ' . $e->getMessage(), [
                    'exception' => get_class($e),
                    'file'      => $e->getFile(),
                    'line'      => $e->getLine(),
                ]);
                return response()->json([
                    'message' => 'Erro interno no servidor. Tente novamente em instantes.',
                ], 500);
            }
        });
    })->create();
