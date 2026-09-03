<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class EnsureWebRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if ($user === null) {
            abort(401, 'User is not logged in.');
        }

        $hasRole = DB::table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('model_has_roles.model_id', $user->getKey())
            ->where('model_has_roles.model_type', $user::class)
            ->where('roles.guard_name', 'web')
            ->whereIn('roles.name', $roles)
            ->exists();

        if (! $hasRole) {
            abort(403, 'User does not have the required role.');
        }

        return $next($request);
    }
}
