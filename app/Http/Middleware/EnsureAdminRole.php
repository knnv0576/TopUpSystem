<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureAdminRole
{
    public function handle(Request $request, Closure $next, string $role): Response
    {
        if (Auth::guard('admin')->user()?->role !== $role) {
            abort(403, '這個角色不能執行此操作');
        }

        return $next($request);
    }
}
