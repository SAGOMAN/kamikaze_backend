<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\RespondsWithPaginatedList;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class UserController extends Controller
{
    use RespondsWithPaginatedList;

    public function index(Request $request): JsonResponse
    {
        $this->ensureAdmin($request);

        $query = User::query()->orderBy('name');

        if ($request->filled('is_active')) {
            $query->where('is_active', filter_var($request->query('is_active'), FILTER_VALIDATE_BOOLEAN));
        }

        if ($request->filled('role')) {
            $query->where('role', $request->query('role'));
        }

        $this->applySearch($query, $request, function ($q, string $like) {
            $q->where('name', 'like', $like)
                ->orWhere('email', 'like', $like);
        });

        return $this->respondList($request, $query);
    }

    public function store(Request $request): JsonResponse
    {
        $this->ensureAdmin($request);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::defaults()],
            'role' => ['required', Rule::in(User::ROLES)],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $data['is_active'] = $data['is_active'] ?? true;

        $user = User::query()->create($data);

        return response()->json($user, 201);
    }

    public function show(Request $request, User $user): JsonResponse
    {
        $this->ensureAdmin($request);

        return response()->json($user);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $this->ensureAdmin($request);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'email' => ['sometimes', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'role' => ['sometimes', Rule::in(User::ROLES)],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $actor = $request->user();

        if (array_key_exists('is_active', $data) && ! $data['is_active'] && $user->id === $actor->id) {
            throw ValidationException::withMessages([
                'is_active' => ['No puedes desactivar tu propio usuario.'],
            ]);
        }

        $becomingInactive = array_key_exists('is_active', $data) && ! $data['is_active'] && $user->isAdmin();
        $losingAdmin = array_key_exists('role', $data)
            && $data['role'] !== User::ROLE_ADMIN
            && $user->isAdmin();

        if (($becomingInactive || $losingAdmin) && $this->isLastActiveAdmin($user)) {
            throw ValidationException::withMessages([
                $becomingInactive ? 'is_active' : 'role' => [
                    'Debe existir al menos un administrador activo.',
                ],
            ]);
        }

        $user->update($data);

        return response()->json($user->fresh());
    }

    public function updatePassword(Request $request, User $user): JsonResponse
    {
        $this->ensureAdmin($request);

        $data = $request->validate([
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        $user->update(['password' => $data['password']]);

        return response()->json(['message' => 'Contraseña actualizada correctamente.']);
    }

    private function ensureAdmin(Request $request): void
    {
        /** @var User|null $user */
        $user = $request->user();

        if (! $user || ! $user->isAdmin()) {
            abort(403, 'No tienes permiso para gestionar usuarios.');
        }
    }

    private function isLastActiveAdmin(User $user): bool
    {
        return ! User::query()
            ->where('role', User::ROLE_ADMIN)
            ->where('is_active', true)
            ->where('id', '!=', $user->id)
            ->exists();
    }
}
