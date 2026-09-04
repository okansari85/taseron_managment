<?php

namespace App\Services;

use App\Models\User;
use App\Repositories\RolePermissionRepository;
use App\Repositories\UserAuthorizationRepository;

class UserAuthorizationService
{
    public function __construct(
        private UserAuthorizationRepository $repository,
        private RolePermissionRepository $roleRepository,
    ) {
    }

    public function all()
    {
        return $this->repository->all();
    }

    public function find(int $id): User
    {
        return $this->repository->find($id);
    }

    public function create(string $name, string $email, string $password, ?string $roleName = null, ?int $contractorId = null): User
    {
        $user = User::query()->create([
            'name' => $name,
            'email' => $email,
            'password' => $password,
            'contractor_id' => $contractorId,
        ]);

        if ($roleName !== null && $roleName !== '') {
            $role = $this->roleRepository->findRole($roleName, $user->getDefaultGuardName());
            $user->roles()->sync([$role->getKey()]);
        }

        return $this->repository->find($user->id);
    }

    public function assignRole(User $user, string $roleName): User
    {
        $role = $this->roleRepository->findRole($roleName, $user->getDefaultGuardName());
        $user->roles()->sync([$role->getKey()]);

        return $this->repository->find($user->id);
    }

    public function syncPermissions(User $user, array $permissionNames): User
    {
        return $this->repository->syncUserPermissions($user, array_values(array_unique($permissionNames)));
    }

    public function syncDirectPermissions(User $user, array $permissionNames): User
    {
        return $this->repository->syncDirectPermissions($user, array_values(array_unique($permissionNames)));
    }

    public function syncForbiddenPermissions(User $user, array $permissionNames): User
    {
        return $this->repository->syncForbiddenPermissions($user, array_values(array_unique($permissionNames)));
    }

    public function delete(User $user): void
    {
        $user->delete();
    }
}
