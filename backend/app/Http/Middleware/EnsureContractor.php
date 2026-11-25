<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class EnsureContractor
{
    public function handle(Request $request, Closure $next): mixed
    {
        $user = $request->user();
        if (! $user || $user->isCustomer() || ! $user->organization_id) {
            abort(403, 'Раздел доступен только сотрудникам подрядчика');
        }

        return $next($request);
    }
}
