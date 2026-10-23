<?php

namespace App\Http\Controllers;

use App\Services\PlannerIdentity;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Session login for the browser. CalDAV clients authenticate over HTTP Basic
 * against the same account and the same password, so this is one of two doors
 * into one credential rather than a login of its own.
 */
class LoginController extends Controller
{
    public function show(): View
    {
        return view('auth.login');
    }

    public function store(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        // Resolve the one account case-insensitively, then let the guard check the
        // password. Matching on `email` directly would reject the same address
        // typed with a capital letter, because SQLite compares TEXT case-
        // sensitively — the same trap that breaks CalDAV from a phone.
        $user = PlannerIdentity::resolve($credentials['email']);

        $signedIn = $user !== null && Auth::attempt(
            ['id' => $user->getKey(), 'password' => $credentials['password']],
            $request->boolean('remember', true),
        );

        if (! $signedIn) {
            throw ValidationException::withMessages([
                'email' => __('auth.failed'),
            ]);
        }

        $request->session()->regenerate();

        return redirect()->intended(route('planner'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
