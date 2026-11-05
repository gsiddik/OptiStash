<?php

namespace App\Http\Middleware;

use App\Domain\Access\Access;
use App\Domain\Access\PermissionCatalog;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** The warehouse screens are for internal users: a supplier-bound membership uses the supplier portal instead. */
class InternalAccess
{
    public function handle(Request $r, Closure $next): Response
    {
        abort_if(Access::isSupplier($r) || ! Access::can($r, PermissionCatalog::ACCESS), 403, 'Gunakan portal Anda.');

        return $next($r);
    }
}
