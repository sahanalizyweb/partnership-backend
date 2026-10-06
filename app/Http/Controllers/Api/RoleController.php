<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RoleController extends Controller
{
    public function index()
    {
        return Role::with('permissions:id,name')->where('name', '!=', 'Partner')->orderBy('name')->get();
    }

    public function permissions()
    {
        return Permission::orderBy('name')->get()->groupBy(fn ($p) => explode('.', $p->name)[0]);
    }
}
