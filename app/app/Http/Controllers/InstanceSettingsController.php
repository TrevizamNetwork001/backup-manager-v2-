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
        $this->authorize('settings.view');

        $currentTimezone = $timezone->get();
        $timezoneOptions = [
            'America/Rio_Branco' => 'Acre (AC) — Rio Branco',
            'America/Eirunepe' => 'Amazonas (oeste) — Eirunepé',
            'America/Manaus' => 'Amazonas (AM) — Manaus',
            'America/Boa_Vista' => 'Roraima (RR) — Boa Vista',
            'America/Porto_Velho' => 'Rondônia (RO) — Porto Velho',
            'America/Cuiaba' => 'Mato Grosso (MT) — Cuiabá',
            'America/Campo_Grande' => 'Mato Grosso do Sul (MS) — Campo Grande',
            'America/Santarem' => 'Pará (oeste) — Santarém',
            'America/Belem' => 'Amapá e Pará (AP/PA) — Belém',
            'America/Fortaleza' => 'Ceará, Maranhão e Piauí (CE/MA/PI)',
            'America/Recife' => 'Paraíba, Pernambuco e Rio Grande do Norte (PB/PE/RN)',
            'America/Maceio' => 'Alagoas e Sergipe (AL/SE)',
            'America/Bahia' => 'Bahia (BA) — Salvador',
            'America/Araguaina' => 'Tocantins (TO) — Araguaína',
            'America/Sao_Paulo' => 'Brasília, Goiás, Sudeste e Sul (DF/GO/ES/MG/RJ/SP/PR/SC/RS)',
            'America/Noronha' => 'Fernando de Noronha (PE)',
        ];

        if (! array_key_exists($currentTimezone, $timezoneOptions)) {
            $timezoneOptions = [$currentTimezone => "Fuso atual — {$currentTimezone}"] + $timezoneOptions;
        }

        return view('settings.edit', [
            'timezone' => $currentTimezone,
            'currentTime' => $timezone->localNow()->format('d/m/Y H:i:s'),
            'timezoneOptions' => $timezoneOptions,
        ]);
    }

    public function update(Request $request, InstanceTimezone $timezone): RedirectResponse
    {
        $this->authorize('settings.manage');
        $validated = $request->validate([
            'timezone' => ['required', 'string', Rule::in(DateTimeZone::listIdentifiers())],
        ]);
        $timezone->set($validated['timezone']);

        return redirect()->route('settings.edit')->with('success', 'Fuso horário atualizado.');
    }
}
