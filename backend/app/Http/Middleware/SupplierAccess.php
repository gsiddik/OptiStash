<?php

namespace App\Http\Middleware;

use App\Domain\Access\Access;
use App\Domain\Access\PermissionCatalog;
use Closure;
use Illuminate\Http\Request;

/** Only a supplier-bound member holding the supplier portal permission passes; every portal route is then limited to that supplier. */
class SupplierAccess
{
    public function handle(Request $request, Closure $next)
    {
        abort_unless(Access::isSupplier($request) && Access::can($request, 'supplier.portal'), 403, 'Hanya untuk akun portal pemasok.');

        return $next($request);
    }
}
