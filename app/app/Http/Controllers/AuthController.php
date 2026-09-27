<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\AuditEvents;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class AuthController extends Controller
{
    public function create(): View
    {
        return view('auth.login');
    }

    public function store(Request $request, AuditEvents $audit): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string'],
        ]);

        $identifier = $credentials['email'];
        if (! filter_var($identifier, FILTER_VALIDATE_EMAIL)) {
            // Names are not unique, so only an unambiguous match can sign in.
            $matches = User::query()->where('name', $identifier)->limit(2)->pluck('email');
            $credentials['email'] = $matches->count() === 1 ? $matches->first() : $identifier;
        }

        // A single generic failure message is used for both "wrong credentials"
        // and "account disabled" so the response never discloses which case it is.
        $genericFailure = fn () => back()
            ->withErrors(['email' => 'Credenciais inválidas ou acesso indisponível.'])
            ->onlyInput('email');

        if (! Auth::attempt($credentials, $request->boolean('remember'))) {
            $audit->record('auth.login_failed', 'user', null, $identifier, 'failed', [], null, $request->ip());
            return $genericFailure();
        }

        if (! Auth::user()->is_active) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            $audit->record('auth.login_failed', 'user', null, $identifier, 'failed', [], null, $request->ip());
            return $genericFailure();
        }

        $request->session()->regenerate();
        $audit->record('auth.login', 'user', (string) Auth::id(), Auth::user()->email, 'success', [], Auth::id(), $request->ip());

        return redirect()->intended(route('dashboard'));
    }

    public function destroy(Request $request, AuditEvents $audit): RedirectResponse
    {
        $audit->record('auth.logout', 'user', (string) Auth::id(), Auth::user()->email, 'success', [], Auth::id(), $request->ip());
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
