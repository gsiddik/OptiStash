<?php

use App\Http\Middleware\ApiResponseHeaders;
use App\Http\Middleware\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(web: __DIR__.'/../routes/web.php', commands: __DIR__.'/../routes/console.php', health: '/up')
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias(['tenant' => TenantContext::class]);
        $middleware->web(append: [ApiResponseHeaders::class]);
        // Server-to-server call from OptiNexus, authenticated by the signed logout token instead of a CSRF token.
        $middleware->validateCsrfTokens(except: ['api/v1/integration/optinexus/backchannel-logout', 'api/v1/integration/optinexus/events']);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(fn (Request $request) => $request->is('api/*') || $request->expectsJson());
        $exceptions->render(function (QueryException $e, Request $request) {
            if ($request->is('api/*') && in_array((string) $e->getCode(), ['23505', '23000'], true)) {
                return response()->json(['message' => 'Data telah digunakan atau alokasi bersamaan terdeteksi. Muat ulang dan periksa data.'], 409);
            }
        });
    })->create();
