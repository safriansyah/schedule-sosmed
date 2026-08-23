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
            Auth::guard('web')->logout();

            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->with('toast', [
                'message' => 'Akun Anda dinonaktifkan. Hubungi administrator.',
                'type' => 'error',
            ]);
        }

        return $next($request);
    }
}
