<?php

namespace App\Http\Middleware;

use App\Services\PlannerLock;
use Closure;
use Illuminate\Http\Request;

class PlannerWriteLock
{
    public function handle(Request $request, Closure $next)
    {
        return $request->isMethod('GET') ? $next($request) : app(PlannerLock::class)->run(fn () => $next($request));
    }
}
