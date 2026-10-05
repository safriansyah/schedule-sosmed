<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Deactivating a user must cut access immediately, not at their next login —
 * "remember me" sessions would otherwise outlive the offboarding.
 */
class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        if (Auth::check() && ! Auth::user()->is_active) {
            return $this->signOut($request, 'Akun Anda dinonaktifkan. Hubungi administrator.');
        }

        // The login schedule holds for a session already open as well: when
        // the window closes at 17:00, a tab left open does not keep working.
        if (Auth::check() && ! Auth::user()->loginAllowedAt(now())) {
            return $this->signOut($request, 'Akses login Anda berada di luar jadwal yang telah ditentukan.');
        }

        return $next($request);
    }

    private function signOut(Request $request, string $message): Response
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        if ($request->expectsJson()) {
            return response()->json(['message' => $message], 401);
        }

        return redirect()->route('login')->with('toast', ['message' => $message, 'type' => 'error']);
    }
}
