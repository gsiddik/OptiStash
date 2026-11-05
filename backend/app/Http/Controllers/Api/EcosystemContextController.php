<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class EcosystemContextController extends Controller
{
    /** How this deployment runs: standalone or SaaS under OptiNexus, and who is the identity provider. */
    public function getContext(): JsonResponse
    {
        return response()->json(['data' => [
            'deployment_mode' => config('optistash.deployment_mode', 'standalone'),
            'identity_mode' => config('optistash.identity_mode', 'local'),
        ]]);
    }
}
