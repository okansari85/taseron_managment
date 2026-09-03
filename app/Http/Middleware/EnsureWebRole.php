<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureWebRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if ($user === null) {
            abort(401, 'User is not logged in.');
        }

        foreach ($roles as $role) {
            if ($user->hasRole($role, 'web')) {
                return $next($request);
            }
        }

        abort(403, 'User does not have the required role.');
    }
}
