<?php

namespace App\Http\Controllers;

use App\Enums\Permission;
use App\Enums\SocialPlatform;
use App\Http\Requests\SocialAccountRequest;
use App\Models\AccountMetric;
use App\Models\SocialAccount;
use App\Services\ActivityLogger;
use App\Services\Publishing\AccountVerifier;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;
use Throwable;

class SocialAccountController extends Controller
{
    public function __construct(
        private readonly AccountVerifier $verifier,
        private readonly ActivityLogger $log,
    ) {}

    public function index(): View
    {
        $this->authorize(Permission::ViewAccounts->value);

        $accounts = SocialAccount::withCount('schedules')->latest()->get();

        // Yesterday's snapshot per account — used to show follower growth.
        $previous = AccountMetric::whereIn('social_account_id', $accounts->pluck('id'))
            ->where('captured_on', '<', today())
            ->orderByDesc('captured_on')
            ->get()
            ->unique('social_account_id')
            ->keyBy('social_account_id');

        return view('accounts.index', compact('accounts', 'previous'));
    }

    public function create(): View
    {
        $this->authorize(Permission::ManageAccounts->value);

        return view('accounts.create', ['platforms' => SocialPlatform::cases()]);
    }

    public function store(SocialAccountRequest $request): RedirectResponse
    {
        $account = new SocialAccount;

        $account->forceFill([
            ...$request->safe()->only('platform', 'name', 'username', 'is_active'),
            'access_token' => $request->input('access_token'),
        ])->save();

        $this->log->log('account.created', "Akun {$account->name} ditambahkan", $account);

        return $this->verifyAndRedirect($account, 'Akun ditambahkan.');
    }

    public function edit(SocialAccount $account): View
    {
        $this->authorize(Permission::ManageAccounts->value);

        return view('accounts.edit', [
            'account' => $account,
            'platforms' => SocialPlatform::cases(),
        ]);
    }

    public function update(SocialAccountRequest $request, SocialAccount $account): RedirectResponse
    {
        $account->forceFill($request->safe()->only('platform', 'name', 'username', 'is_active'));

        // Blank token means "keep the existing one".
        if (filled($request->input('access_token'))) {
            $account->access_token = $request->input('access_token');
        }

        $account->save();

        $this->log->log('account.updated', "Akun {$account->name} diperbarui", $account);

        return $this->verifyAndRedirect($account, 'Akun diperbarui.');
    }

    public function destroy(SocialAccount $account): RedirectResponse
    {
        $this->authorize(Permission::ManageAccounts->value);

        $this->log->log('account.deleted', "Akun {$account->name} dihapus", $account);
        $account->delete();

        return redirect()
            ->route('accounts.index')
            ->with('toast', ['message' => 'Akun dihapus.', 'type' => 'success']);
    }

    /** Re-check credentials and refresh the profile snapshot. */
    public function verify(SocialAccount $account): RedirectResponse
    {
        $this->authorize(Permission::ManageAccounts->value);

        try {
            $this->verifier->verify($account);
        } catch (Throwable $e) {
            return back()->with('toast', ['message' => $e->getMessage(), 'type' => 'error']);
        }

        return back()->with('toast', [
            'message' => "Koneksi berhasil — {$account->fresh()->handle()}.",
            'type' => 'success',
        ]);
    }

    /**
     * Saving an account immediately checks its token, so a bad credential is
     * caught here rather than silently at publish time.
     */
    private function verifyAndRedirect(SocialAccount $account, string $message): RedirectResponse
    {
        try {
            $this->verifier->verify($account);
        } catch (Throwable $e) {
            return redirect()
                ->route('accounts.index')
                ->with('toast', ['message' => "{$message} Namun koneksi gagal: {$e->getMessage()}", 'type' => 'warning']);
        }

        return redirect()
            ->route('accounts.index')
            ->with('toast', ['message' => "{$message} Koneksi terverifikasi.", 'type' => 'success']);
    }
}
