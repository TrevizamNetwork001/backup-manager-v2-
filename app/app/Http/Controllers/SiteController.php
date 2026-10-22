<?php

namespace App\Http\Controllers;

use App\Models\Site;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
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

        DB::transaction(function () use ($validated): void {
            $this->ensureNameAvailable($validated['name']);
            Site::create($validated);
        });

        return redirect()
            ->route('sites.index')
            ->with('success', 'Site / POP cadastrado com sucesso.');
    }

    public function edit(Site $site): View
    {
        $this->authorize('sites.manage');
        $sites = Site::query()
            ->orderBy('name')
            ->paginate(20);

        return view('sites.index', ['sites' => $sites, 'editingSite' => $site]);
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

        DB::transaction(function () use ($site, $validated): void {
            if (mb_strtolower($site->name) !== mb_strtolower($validated['name'])) {
                $this->ensureNameAvailable($validated['name'], $site->id);
            }
            $site->update($validated);
        });

        return redirect()
            ->route('sites.index', ['page' => $request->query('page')])
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
            'name' => preg_replace('/\s+/u', ' ', trim((string) $request->input('name'))),
            'code' => $code !== '' ? strtoupper($code) : null,
            'location' => trim((string) $request->input('location')) ?: null,
            'description' => trim((string) $request->input('description')) ?: null,
            'is_active' => $request->boolean('is_active'),
        ]);
    }

    private function ensureNameAvailable(string $name, ?int $exceptSiteId = null): void
    {
        $normalizedName = mb_strtolower($name);
        if (DB::getDriverName() === 'pgsql') {
            DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', [$normalizedName]);
        }

        $exists = Site::query()->whereRaw('LOWER(name) = ?', [$normalizedName])
            ->when($exceptSiteId, fn ($query) => $query->where('id', '!=', $exceptSiteId))->exists();
        if ($exists) {
            throw ValidationException::withMessages(['name' => 'Já existe um Site / POP com este nome.']);
        }
    }
}
