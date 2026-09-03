<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\RolePermissionService;
use App\Services\UserAuthorizationService;
use App\Services\UserScopeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class UserAuthorizationController extends Controller
{
    public function __construct(
        private UserAuthorizationService $users,
        private UserScopeService $scopes,
        private RolePermissionService $roles,
    ) {
    }

    public function index(): JsonResponse
    {
        return response()->json($this->users->all());
    }

    public function show(User $user): JsonResponse
    {
        return response()->json($this->users->find($user->id));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
            'role' => [
                'nullable',
                'string',
                Rule::exists('roles', 'name')->where(
                    fn ($query) => $query->where('guard_name', 'web')
                ),
            ],
        ]);

        return response()->json($this->users->create(
            $data['name'],
            $data['email'],
            $data['password'],
            $data['role'] ?? null,
        ), 201);
    }

    public function destroy(User $user): JsonResponse
    {
        $this->users->delete($user);

        return response()->json(['message' => 'User deleted.']);
    }

    public function assignRole(Request $request, User $user): JsonResponse
    {
        $data = $request->validate([
            'role' => [
                'required',
                'string',
                Rule::exists('roles', 'name')->where(
                    fn ($query) => $query->where('guard_name', $user->getDefaultGuardName())
                ),
            ],
        ]);

        return response()->json($this->users->assignRole($user, $data['role']));
    }

    public function permissions(Request $request, User $user): JsonResponse
    {
        $data = $request->validate([
            'permissions' => ['required', 'array'],
            'permissions.*' => ['string', 'distinct'],
        ]);

        return response()->json($this->users->syncPermissions($user, $data['permissions']));
    }

    public function forbiddenPermissions(Request $request, User $user): JsonResponse
    {
        $data = $request->validate([
            'permissions' => ['required', 'array'],
            'permissions.*' => ['string', 'distinct'],
        ]);

        return response()->json($this->users->syncForbiddenPermissions($user, $data['permissions']));
    }

    public function roles(): JsonResponse
    {
        return response()->json($this->roles->roles());
    }

    public function permissionList(): JsonResponse
    {
        return response()->json($this->roles->permissions());
    }

    public function updateRolePermissions(Request $request, string $role): JsonResponse
    {
        $data = $request->validate([
            'permissions' => ['required', 'array'],
            'permissions.*' => ['string', 'distinct'],
        ]);

        return response()->json($this->roles->sync($role, $data['permissions']));
    }

    public function scopes(User $user): JsonResponse
    {
        return response()->json($this->scopes->all($user));
    }

    public function syncScopes(Request $request, User $user): JsonResponse
    {
        $data = $request->validate([
            'scopes' => ['required', 'array'],
            'scopes.*.scope_type' => ['required', Rule::in(['tenant', 'organization', 'location'])],
            'scopes.*.scope_id' => ['required', 'integer', 'min:1'],
        ]);

        return response()->json($this->scopes->sync($user, $data['scopes']));
    }

    public function attachScope(Request $request, User $user): JsonResponse
    {
        $data = $request->validate([
            'scope_type' => ['required', Rule::in(['tenant', 'organization', 'location'])],
            'scope_id' => ['required', 'integer', 'min:1'],
        ]);

        return response()->json($this->scopes->attach($user, $data['scope_type'], (int) $data['scope_id']));
    }

    public function detachScope(Request $request, User $user): JsonResponse
    {
        $data = $request->validate([
            'scope_type' => ['required', Rule::in(['tenant', 'organization', 'location'])],
            'scope_id' => ['required', 'integer', 'min:1'],
        ]);

        return response()->json($this->scopes->detach($user, $data['scope_type'], (int) $data['scope_id']));
    }
}
