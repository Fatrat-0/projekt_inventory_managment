<?php

use Illuminate\Foundation\Application;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias([
            'role' => \App\Http\Middleware\RoleMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(fn (Request $request, Throwable $exception) =>
            $request->is('transfer-intents', 'transfer-intents/*', 'shipment-plans', 'shipment-plans/*', 'shipment-receipt-items/*')
                || $request->expectsJson());

        $exceptions->render(function (AuthenticationException $exception, Request $request) {
            if ($request->is('shipment-receipt-items/*')) {
                return response()->json(['message' => 'A betárolás használatához jelentkezz be.'], 401);
            }
            if ($request->is('shipment-plans', 'shipment-plans/*')) {
                return response()->json(['message' => 'A fuvartervek használatához jelentkezz be.'], 401);
            }
            if ($request->is('transfer-intents', 'transfer-intents/*')) {
                return response()->json(['message' => 'Az átmozgatási igények használatához jelentkezz be.'], 401);
            }
        });

        $exceptions->render(function (ValidationException $exception, Request $request) {
            if ($request->is('transfer-intents', 'transfer-intents/*', 'shipment-plans', 'shipment-plans/*', 'shipment-receipt-items/*')) {
                $errors = $exception->errors();
                return response()->json([
                    'message' => collect($errors)->flatten()->first() ?? 'Ellenőrizd a megadott adatokat.',
                    'errors' => $errors,
                ], $exception->status);
            }
        });

        $exceptions->render(function (HttpException $exception, Request $request) {
            if ($request->is('shipment-receipt-items/*')) {
                $status = $exception->getStatusCode();
                $message = match ($status) {
                    403 => $exception->getMessage() ?: 'A betároláshoz célraktári jogosultság szükséges.',
                    404 => 'A betárolási tétel vagy kapcsolódó adat nem található.',
                    405 => 'Ez a művelet ezen a végponton nem érhető el.',
                    409 => $exception->getMessage() ?: 'A betárolás állapota vagy a műveleti azonosító ütközik.',
                    419 => 'A munkamenet lejárt. Frissítsd az oldalt, és próbáld újra.',
                    default => null,
                };
                return $message === null ? null : response()->json(['message' => $message], $status, $exception->getHeaders());
            }
            if ($request->is('shipment-plans', 'shipment-plans/*')) {
                $status = $exception->getStatusCode();
                $message = match ($status) {
                    403 => $exception->getMessage() ?: 'Nincs jogosultságod ehhez a fuvartervezési művelethez.',
                    404 => 'A kért fuvarterv vagy kapcsolódó adat nem található.',
                    405 => 'Ez a művelet ezen a végponton nem érhető el.',
                    409 => $exception->getMessage() ?: 'A fuvarterv állapota vagy a műveleti azonosító ütközik.',
                    419 => 'A munkamenet lejárt. Frissítsd az oldalt, és próbáld újra.',
                    default => null,
                };
                return $message === null ? null : response()->json(['message' => $message], $status, $exception->getHeaders());
            }
            if ($request->is('transfer-intents', 'transfer-intents/*')) {
                $status = $exception->getStatusCode();
                $message = match ($status) {
                    403 => $exception->getMessage() ?: 'Nincs jogosultságod ehhez a művelethez.',
                    404 => 'Az átmozgatási igény nem található.',
                    405 => 'Ez a művelet ezen a végponton nem érhető el.',
                    409 => $exception->getMessage() ?: 'Az igény állapota vagy a műveleti azonosító ütközik.',
                    419 => 'A munkamenet lejárt. Frissítsd az oldalt, és próbáld újra.',
                    default => null,
                };
                return $message === null ? null : response()->json(['message' => $message], $status, $exception->getHeaders());
            }

            if (! ($exception->getPrevious() instanceof TokenMismatchException)
                || ! $request->isMethod('POST') || ! $request->is('login')
                || $request->expectsJson()) {
                return null;
            }

            // Reload the form, never replay credentials or bypass CSRF validation.
            $email = $request->input('email');
            return redirect()->route('login', [], 303)
                ->withInput(['email' => is_string($email) ? mb_substr($email, 0, 254) : ''])
                ->with('status', 'A bejelentkezési űrlap lejárt. Az oldalt frissítettük; kérlek, add meg újra a jelszavad.');
        });
    })->create();
