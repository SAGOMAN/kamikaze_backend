<?php

use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );

        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return response()->json([
                'message' => 'Tu sesión expiró o no estás autenticado. Vuelve a iniciar sesión.',
            ], 401);
        });

        $exceptions->render(function (HttpExceptionInterface $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            $message = trim($e->getMessage());
            if ($message === '' || strcasecmp($message, 'Unauthorized') === 0) {
                $message = match ($e->getStatusCode()) {
                    401 => 'Tu sesión expiró o no estás autenticado. Vuelve a iniciar sesión.',
                    403 => 'No tienes permiso para realizar esta acción.',
                    404 => 'No encontramos lo que buscabas.',
                    429 => 'Demasiados intentos. Espera un momento e inténtalo de nuevo.',
                    default => 'Ocurrió un error. Inténtalo de nuevo.',
                };
            }

            return response()->json([
                'message' => $message,
            ], $e->getStatusCode());
        });

        $exceptions->render(function (UniqueConstraintViolationException $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return response()->json([
                'message' => 'El registro ya existe.',
                'errors' => [
                    'record' => ['El registro ya existe.'],
                ],
            ], 422);
        });

        $exceptions->render(function (QueryException $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            // UniqueConstraintViolationException extends QueryException; handled above.
            if ($e instanceof UniqueConstraintViolationException) {
                return null;
            }

            return response()->json([
                'message' => 'Ocurrió un error interno. Inténtalo más tarde.',
            ], 500);
        });
    })->create();
