<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureActiveSession
{
    public const VERSION_KEY = 'account_access_version';

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user && (!$user->isActive() || (int) $user->access_version !== $request->session()->get(self::VERSION_KEY, 0))) {
            Auth::guard('web')->logoutCurrentDevice();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            if (!$request->routeIs('login', 'logout')) {
                throw new AuthenticationException('A munkamenet érvényét vesztette. Jelentkezz be újra.', ['web']);
            }

            return redirect()->route('login');
        }

        if ($user?->password_change_required && !$request->routeIs('password.setup', 'password.update', 'logout')) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'A folytatáshoz állíts be saját jelszót.'], 403);
            }

            return redirect()->route('password.setup');
        }

        return $next($request);
    }
}
