<?php

namespace App\Http\Controllers;

use App\Services\InstanceTimezone;
use DateTimeZone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class InstanceSettingsController extends Controller
{
    public function edit(InstanceTimezone $timezone): View
    {
        abort_unless(auth()->user()->is_admin, 403);

        return view('settings.edit', [
            'timezone' => $timezone->get(),
            'currentTime' => $timezone->localNow()->format('d/m/Y H:i:s'),
            'timezones' => DateTimeZone::listIdentifiers(),
        ]);
    }

    public function update(Request $request, InstanceTimezone $timezone): RedirectResponse
    {
        abort_unless(auth()->user()->is_admin, 403);
        $validated = $request->validate([
            'timezone' => ['required', 'string', Rule::in(DateTimeZone::listIdentifiers())],
        ]);
        $timezone->set($validated['timezone']);

        return redirect()->route('settings.edit')->with('success', 'Fuso horário atualizado.');
    }
}
