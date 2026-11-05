<?php

namespace App\Http\Middleware;

use App\Domain\Access\Access;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class WriteAccess
{
    public function handle(Request $r, Closure $next): Response
    {
        Access::authorize($r, 'enterprise.manage', 'Akses hanya baca.');

        return $next($r);
    }
}
