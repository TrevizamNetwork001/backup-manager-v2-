<?php

namespace App\Http\Controllers;

use App\Models\Device;
use App\Services\OltFtpWizard;
use App\Services\FtpServerSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class OltFtpWizardController extends Controller
{
    public function saveServer(Request $request, Device $device, OltFtpWizard $wizard, FtpServerSettings $settings): RedirectResponse
    {
        $this->authorize('ftp.manage');
        $wizard->assertOlt($device);
        $validated = $request->validate([
            'ftp_host' => ['required', 'string', 'max:255', function ($attribute, $value, $fail) {
                if (! FtpServerSettings::validAddress(trim($value))) $fail('Informe um IPv4, IPv6 ou hostname válido.');
            }],
            'ftp_passive_address' => ['nullable', 'string', 'max:255', function ($attribute, $value, $fail) {
                if ($value !== null && ! FtpServerSettings::validAddress(trim($value))) $fail('Informe um IPv4, IPv6 ou hostname válido.');
            }],
            'ftp_port' => ['nullable', 'integer', 'in:21'],
        ]);
        $effectivePassive = trim((string) config('backup.ftp_passive_address'));
        if (trim((string) ($validated['ftp_passive_address'] ?? '')) !== $effectivePassive) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'ftp_passive_address' => 'O endereço passivo é definido no .env do Compose. Altere-o e recrie app e ftp.',
            ]);
        }
        $settings->set($validated['ftp_host'], $effectivePassive ?: null, 21);

        return redirect()->route('devices.edit', [$device, 'olt_wizard' => 1]);
    }

    public function status(Device $device, OltFtpWizard $wizard): JsonResponse
    {
        $this->authorize('ftp.view');
        $wizard->assertOlt($device);
        $snapshot = $wizard->snapshot($device);
        $execution = $snapshot['execution'];
        return response()->json([
            'state' => $snapshot['state'],
            'current_step' => $snapshot['current_step'],
            'execution_id' => $execution?->id,
            'execution_status' => $execution?->status,
        ])->header('Cache-Control', 'no-store, private');
    }

    public function confirm(Request $request, Device $device, OltFtpWizard $wizard): RedirectResponse
    {
        $this->authorize('ftp.manage');
        $request->validate(['olt_configured' => ['accepted']]);
        $wizard->confirm($device);
        return redirect()->route('devices.edit', [$device, 'olt_wizard' => 1]);
    }

    public function test(Device $device, OltFtpWizard $wizard): RedirectResponse
    {
        $this->authorize('ftp.manage');
        $wizard->startTest($device);
        return redirect()->route('devices.edit', [$device, 'olt_wizard' => 1]);
    }
}
