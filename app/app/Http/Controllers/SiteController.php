<?php

namespace App\Http\Controllers;

use App\Models\Site;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class SiteController extends Controller
{
    public function index(): View
    {
        $this->authorize('sites.view');
        $sites = Site::query()
            ->orderBy('name')
            ->paginate(20);

        return view('sites.index', compact('sites'));
    }

    public function create(): View
    {
        $this->authorize('sites.manage');

        return view('sites.create');
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('sites.manage');
        $this->normalize($request);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:50', 'unique:sites,code'],
            'location' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['required', 'boolean'],
        ]);

        Site::create($validated);

        return redirect()
            ->route('sites.index')
            ->with('success', 'Site / POP cadastrado com sucesso.');
    }

    public function edit(Site $site): View
    {
        $this->authorize('sites.manage');

        return view('sites.edit', compact('site'));
    }

    public function update(Request $request, Site $site): RedirectResponse
    {
        $this->authorize('sites.manage');
        $this->normalize($request);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => [
                'nullable',
                'string',
                'max:50',
                Rule::unique('sites', 'code')->ignore($site->id),
            ],
            'location' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['required', 'boolean'],
        ]);

        $site->update($validated);

        return redirect()
            ->route('sites.index')
            ->with('success', 'Site / POP atualizado com sucesso.');
    }

    public function destroy(Site $site): RedirectResponse
    {
        $this->authorize('sites.delete');

        $deviceCount = $site->devices()->count();
        if ($deviceCount > 0) {
            $names = $site->devices()->orderBy('name')->limit(5)->pluck('name')->implode(', ');
            return redirect()->route('sites.index')->with('warning',
                "Remova ou realoque {$deviceCount} equipamento(s) antes de excluir este Site / POP: {$names}".
                ($deviceCount > 5 ? '…' : '.'));
        }

        $site->delete();

        return redirect()
            ->route('sites.index')
            ->with('success', 'Site / POP removido com sucesso.');
    }

    private function normalize(Request $request): void
    {
        $code = trim((string) $request->input('code'));

        $request->merge([
            'name' => trim((string) $request->input('name')),
            'code' => $code !== '' ? strtoupper($code) : null,
            'location' => trim((string) $request->input('location')) ?: null,
            'description' => trim((string) $request->input('description')) ?: null,
            'is_active' => $request->boolean('is_active'),
        ]);
    }
}
