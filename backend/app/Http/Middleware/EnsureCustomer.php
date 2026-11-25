<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class EnsureCustomer
{
    public function handle(Request $request, Closure $next): mixed
    {
        $user = $request->user();
        if (! $user || ! $user->isCustomer() || ! $user->customer_id) {
            abort(403, 'Раздел доступен только представителю заказчика');
        }

        return $next($request);
    }
}
