<?php

use App\Http\Controllers\Api\AccessController as A;
use Illuminate\Support\Facades\Route;

// Tenant role management; permission checks live in the controller (access.role.view / access.role.manage / access.user.manage).
Route::get('access/permissions', [A::class, 'permissions']);
Route::get('access/roles', [A::class, 'roles']);
Route::post('access/roles', [A::class, 'createRole']);
Route::put('access/roles/{id}', [A::class, 'updateRole'])->whereUuid('id');
Route::delete('access/roles/{id}', [A::class, 'deleteRole'])->whereUuid('id');
Route::get('access/members', [A::class, 'members']);
Route::put('access/members/{membership}/roles', [A::class, 'assign'])->whereNumber('membership');
