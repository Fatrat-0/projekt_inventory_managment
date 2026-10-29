<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
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
        $exceptions->render(function (HttpException $exception, Request $request) {
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
