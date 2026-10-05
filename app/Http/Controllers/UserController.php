<?php

namespace App\Http\Controllers;

use App\Enums\Permission;
use App\Http\Requests\UserRequest;
use App\Models\Role;
use App\Models\User;
use App\Services\ActivityLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;

class UserController extends Controller
{
    public function __construct(private readonly ActivityLogger $log) {}

    public function index(Request $request): View
    {
        $this->authorize(Permission::ViewUsers->value);

        $users = User::with('role')
            ->when($request->input('q'), function ($query, string $term) {
                $like = '%'.$term.'%';
                $query->where(fn ($q) => $q->where('name', 'like', $like)->orWhere('email', 'like', $like));
            })
            ->when($request->input('role'), fn ($q, $id) => $q->where('role_id', $id))
            ->orderBy('name')
            ->paginate(15)
            ->withQueryString();

        return view('users.index', [
            'users' => $users,
            'roles' => Role::orderByDesc('level')->get(),
            'filters' => $request->only('q', 'role'),
        ]);
    }

    public function create(): View
    {
        $this->authorize(Permission::ManageUsers->value);

        return view('users.create', ['roles' => Role::orderByDesc('level')->get()]);
    }

    public function store(UserRequest $request): RedirectResponse
    {
        $user = new User;

        $user->fill($request->safe()->only('name', 'email', 'phone'));
        // Raw: the model decides whether to hash it or store a pasted hash.
        $user->password = $request->input('password');

        // Guarded against mass assignment — set explicitly.
        $user->role_id = $request->integer('role_id');
        $user->is_active = $request->boolean('is_active');
        $this->applyLoginSchedule($user, $request);

        $user->save();

        $this->log->log('user.created', "Pengguna {$user->name} dibuat", $user);

        return redirect()
            ->route('users.index')
            ->with('toast', ['message' => 'Pengguna berhasil dibuat.', 'type' => 'success']);
    }

    public function edit(User $user): View
    {
        $this->authorize(Permission::ManageUsers->value);

        return view('users.edit', [
            'user' => $user,
            'roles' => Role::orderByDesc('level')->get(),
        ]);
    }

    public function update(UserRequest $request, User $user): RedirectResponse
    {
        $user->fill($request->safe()->only('name', 'email', 'phone'));

        if (filled($request->input('password'))) {
            $user->password = $request->input('password');
        }

        $user->role_id = $request->integer('role_id');

        // Never let an admin lock themselves out of their own account.
        $user->is_active = $user->is($request->user())
            ? true
            : $request->boolean('is_active');

        $this->applyLoginSchedule($user, $request);

        $user->save();

        $this->log->log('user.updated', "Pengguna {$user->name} diperbarui", $user);

        return redirect()
            ->route('users.index')
            ->with('toast', ['message' => 'Pengguna diperbarui.', 'type' => 'success']);
    }

    /**
     * Jadwal login. Only a Super Admin sets it, and never on their own account:
     * a schedule that closes while they are the last admin would lock everyone
     * out of the place where it can be changed back. Anyone else submitting the
     * form leaves the existing schedule exactly as it was.
     */
    private function applyLoginSchedule(User $user, UserRequest $request): void
    {
        if (! $request->user()->isSuperAdmin() || $user->is($request->user())) {
            return;
        }

        $user->forceFill([
            'login_schedule_enabled' => $request->boolean('login_schedule_enabled'),
            'login_start_date' => $request->input('login_start_date') ?: null,
            'login_end_date' => $request->input('login_end_date') ?: null,
            'login_start_time' => $request->input('login_start_time') ?: null,
            'login_end_time' => $request->input('login_end_time') ?: null,
        ]);
    }

    /**
     * Disable / re-enable an account in one click. A disabled account keeps
     * its history and assignments but cannot log in, and one that is logged
     * in right now is signed out on its next request (EnsureUserIsActive).
     */
    public function toggleActive(Request $request, User $user): RedirectResponse
    {
        $this->authorize(Permission::ManageUsers->value);

        if ($user->is($request->user())) {
            return back()->with('toast', [
                'message' => 'Anda tidak dapat men-disable akun sendiri.',
                'type' => 'error',
            ]);
        }

        $user->forceFill(['is_active' => ! $user->is_active])->save();

        $this->log->log(
            $user->is_active ? 'user.enabled' : 'user.disabled',
            $user->is_active ? "Pengguna {$user->name} diaktifkan kembali" : "Pengguna {$user->name} di-disable",
            $user,
        );

        return back()->with('toast', [
            'message' => $user->is_active
                ? "{$user->name} aktif kembali dan bisa login."
                : "{$user->name} di-disable — tidak bisa login lagi.",
            'type' => 'success',
        ]);
    }

    public function destroy(Request $request, User $user): RedirectResponse
    {
        $this->authorize(Permission::ManageUsers->value);

        if ($user->is($request->user())) {
            return back()->with('toast', [
                'message' => 'Anda tidak dapat menghapus akun sendiri.',
                'type' => 'error',
            ]);
        }

        $this->log->log('user.deleted', "Pengguna {$user->name} dihapus", $user);
        $user->delete();

        return redirect()
            ->route('users.index')
            ->with('toast', ['message' => 'Pengguna dihapus.', 'type' => 'success']);
    }
}
