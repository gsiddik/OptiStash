<?php

namespace App\Http\Controllers;

use App\Domain\Shared\TenantStore;
use Illuminate\Http\Request;

abstract class Controller
{
    /** The tenant-qualified store of the signed-in user (tenant and actor come from the session, never from the request body). */
    public static function storeFor(Request $request): TenantStore
    {
        return new TenantStore($request->attributes->get('tenant')->id, $request->user()->id);
    }
}
