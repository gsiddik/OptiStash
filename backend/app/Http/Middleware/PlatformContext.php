<?php

namespace App\Http\Middleware;

use App\Domain\Access\SessionState;
use App\Domain\Platform\PlatformAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Platform (Superadmin) requests: a session opened by platform login and at least one platform permission, read fresh. */
class PlatformContext
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($request->session()->get('platform') === true, 403, 'Sesi platform diperlukan.');
        abort_unless(SessionState::valid($request, $request->user()), 401, 'Sesi telah berakhir. Silakan masuk kembali.');
        $permissions = PlatformAccess::permissionsFor($request->user()->id);
        abort_if($permissions === [], 403, 'Izin platform diperlukan.');
        $request->attributes->set('platform_permissions', $permissions);

        return $next($request);
    }
}
