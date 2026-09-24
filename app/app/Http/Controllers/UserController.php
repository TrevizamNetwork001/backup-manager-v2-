<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\AuditEvents;
use App\Support\Rbac;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class UserController extends Controller
{
    public function index(): View
    {
        $this->authorize('users.view');

        $users = User::query()->orderBy('name')->paginate(20);

        return view('users.index', compact('users'));
    }

    public function create(): View
    {
        $this->authorize('users.manage');

        return view('users.create');
    }

    public function store(Request $request, AuditEvents $audit): RedirectResponse
    {
        $this->authorize('users.manage');

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'role' => ['required', Rule::in(Rbac::ROLES)],
            'password' => ['required', 'confirmed', Password::min(10), 'max:72'],
            'is_active' => ['required', 'boolean'],
        ]);

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'role' => $validated['role'],
            'password' => $validated['password'],
            'is_active' => $validated['is_active'],
        ]);

        $audit->record('user.created', 'user', (string) $user->id, $user->name, 'success', [
            'target_user_id' => $user->id,
            'target_user_name' => $user->name,
            'target_user_email' => $user->email,
            'new_role' => $user->role,
            'new_status' => $user->is_active ? 'active' : 'disabled',
        ], $request->user()->id, $request->ip());

        return redirect()->route('users.index')->with('success', 'Usuário criado com sucesso.');
    }

    public function edit(User $user): View
    {
        $this->authorize('users.manage');

        return view('users.edit', compact('user'));
    }

    public function update(Request $request, User $user, AuditEvents $audit): RedirectResponse
    {
        $this->authorize('users.manage');

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'role' => ['required', Rule::in(Rbac::ROLES)],
        ]);

        if ($validated['role'] !== $user->role && $user->isLastActiveAdmin()) {
            throw ValidationException::withMessages([
                'role' => 'Não é possível rebaixar o último administrador ativo.',
            ]);
        }

        $oldRole = $user->role;
        $user->fill([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'role' => $validated['role'],
        ])->save();

        if ($oldRole !== $user->role) {
            $audit->record('user.role_changed', 'user', (string) $user->id, $user->name, 'success', [
                'target_user_id' => $user->id,
                'target_user_name' => $user->name,
                'old_role' => $oldRole,
                'new_role' => $user->role,
            ], $request->user()->id, $request->ip());
        } else {
            $audit->record('user.updated', 'user', (string) $user->id, $user->name, 'success', [
                'target_user_id' => $user->id,
                'target_user_name' => $user->name,
                'target_user_email' => $user->email,
            ], $request->user()->id, $request->ip());
        }

        return redirect()->route('users.index')->with('success', 'Usuário atualizado com sucesso.');
    }

    public function status(Request $request, User $user, AuditEvents $audit): RedirectResponse
    {
        $this->authorize('users.manage');

        $validated = $request->validate(['is_active' => ['required', 'boolean']]);

        if (! $validated['is_active'] && $user->id === $request->user()->id) {
            throw ValidationException::withMessages([
                'is_active' => 'Você não pode desativar sua própria conta.',
            ]);
        }

        if (! $validated['is_active'] && $user->isLastActiveAdmin()) {
            throw ValidationException::withMessages([
                'is_active' => 'Não é possível desativar o último administrador ativo.',
            ]);
        }

        $oldStatus = $user->is_active ? 'active' : 'disabled';
        $user->is_active = $validated['is_active'];
        $user->save();

        $audit->record($validated['is_active'] ? 'user.enabled' : 'user.disabled', 'user',
            (string) $user->id, $user->name, 'success', [
                'target_user_id' => $user->id,
                'target_user_name' => $user->name,
                'old_status' => $oldStatus,
                'new_status' => $user->is_active ? 'active' : 'disabled',
            ], $request->user()->id, $request->ip());

        return redirect()->route('users.index')
            ->with('success', $user->is_active ? 'Usuário ativado.' : 'Usuário desativado.');
    }

    public function resetPassword(Request $request, User $user, AuditEvents $audit): RedirectResponse
    {
        $this->authorize('users.manage');

        $validated = $request->validate([
            'password' => ['required', 'confirmed', Password::min(10), 'max:72'],
        ]);

        $user->password = $validated['password'];
        $user->save();

        $audit->record('user.password_reset', 'user', (string) $user->id, $user->name, 'success', [
            'target_user_id' => $user->id,
            'target_user_name' => $user->name,
        ], $request->user()->id, $request->ip());

        return redirect()->route('users.edit', $user)->with('success', 'Senha redefinida com sucesso.');
    }
}
