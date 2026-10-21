<?php

namespace App\Http\Controllers;

use App\Services\AuditEvents;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

class InitialSetupController extends Controller
{
    public function edit(Request $request): View|RedirectResponse
    {
        if (! $request->user()->must_complete_setup) {
            return redirect()->route('dashboard');
        }

        return view('auth.initial-setup');
    }

    public function update(Request $request, AuditEvents $audit): RedirectResponse
    {
        $user = $request->user();
        if (! $user->must_complete_setup) {
            return redirect()->route('dashboard');
        }

        $validated = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'password' => ['required', 'confirmed', 'max:72', Password::min(12)->letters()->mixedCase()->numbers()->symbols()],
        ]);

        DB::transaction(function () use ($user, $validated): void {
            $user->name = $validated['email'];
            $user->email = $validated['email'];
            $user->password = $validated['password'];
            $user->must_complete_setup = false;
            $user->setRememberToken(Str::random(60));
            $user->save();

            DB::table('sessions')->where('user_id', $user->id)->delete();
        });

        $audit->record('auth.initial_setup_completed', 'user', (string) $user->id, $user->email,
            'success', [], $user->id, $request->ip());

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->with('success', 'Acesso configurado. Entre com seu novo e-mail e senha.');
    }
}
