<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class QueryTokenToHeader
{
    public function handle(Request $request, Closure $next): mixed
    {
        $token = $request->query('token');
        if (is_string($token) && $token !== '' && ! $request->bearerToken()) {
            $request->headers->set('Authorization', 'Bearer '.$token);
        }

        return $next($request);
    }
}
