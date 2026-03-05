<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RoleMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        // 1. Ha nincs bejelentkezve, küldjük a login oldalra
        if (!auth()->check()) {
            return redirect('/login');
        }

        // 2. Ha be van jelentkezve, de a szerepköre nincs benne az engedélyezettek listájában, akkor állj!
        if (!in_array(auth()->user()->role, $roles)) {
            abort(403, 'Nincs jogosultságod ehhez az oldalhoz.');
        }

        // 3. Ha minden rendben, mehet tovább az oldalra!
        return $next($request);
    }
}
