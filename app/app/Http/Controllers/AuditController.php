<?php

namespace App\Http\Controllers;

use App\Models\AuditEvent;
use App\Models\User;
use App\Services\InstanceTimezone;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class AuditController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorize('audit.view');

        $filters = $request->validate([
            'period' => ['nullable', Rule::in(['today', '7d', '30d', 'custom'])],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'actor' => ['nullable', 'string', 'max:20'],
            'action' => ['nullable', 'string', 'max:100'],
            'resource_type' => ['nullable', 'string', 'max:100'],
            'result' => ['nullable', 'string', 'max:30'],
            'q' => ['nullable', 'string', 'max:255'],
        ]);

        $timezone = app(InstanceTimezone::class)->get();
        [$from, $to] = $this->resolvePeriod($filters, $timezone);

        $events = AuditEvent::query()
            ->with('actor:id,name,email')
            ->when($from, fn ($query, $value) => $query->where('created_at', '>=', $value))
            ->when($to, fn ($query, $value) => $query->where('created_at', '<=', $value))
            ->when($filters['action'] ?? null, fn ($query, $value) => $query->where('action', $value))
            ->when($filters['resource_type'] ?? null, fn ($query, $value) => $query->where('resource_type', $value))
            ->when($filters['result'] ?? null, fn ($query, $value) => $query->where('result', $value))
            ->when($filters['actor'] ?? null, function ($query, $value) {
                $value === 'system' ? $query->whereNull('actor_user_id') : $query->where('actor_user_id', $value);
            })
            ->when($filters['q'] ?? null, fn ($query, $value) => $this->applySearch($query, $value))
            ->orderByDesc('created_at')->orderByDesc('id')
            ->paginate(50)->withQueryString();

        $actions = AuditEvent::query()->select('action')->distinct()->orderBy('action')->pluck('action');
        $resourceTypes = AuditEvent::query()->select('resource_type')->distinct()->orderBy('resource_type')->pluck('resource_type');
        $results = AuditEvent::query()->select('result')->distinct()->orderBy('result')->pluck('result');
        $users = User::query()->orderBy('name')->get(['id', 'name']);

        return view('audit.index', compact('events', 'filters', 'actions', 'resourceTypes', 'results', 'users'));
    }

    public function show(AuditEvent $auditEvent): View
    {
        $this->authorize('audit.view');
        $auditEvent->load('actor:id,name,email');

        return view('audit.show', compact('auditEvent'));
    }

    private function resolvePeriod(array $filters, string $timezone): array
    {
        $now = CarbonImmutable::now($timezone);

        [$from, $to] = match ($filters['period'] ?? null) {
            'today' => [$now->startOfDay(), $now->endOfDay()],
            '7d' => [$now->subDays(6)->startOfDay(), $now->endOfDay()],
            '30d' => [$now->subDays(29)->startOfDay(), $now->endOfDay()],
            'custom' => [
                ! empty($filters['date_from']) ? CarbonImmutable::parse($filters['date_from'], $timezone)->startOfDay() : null,
                ! empty($filters['date_to']) ? CarbonImmutable::parse($filters['date_to'], $timezone)->endOfDay() : null,
            ],
            default => [null, null],
        };

        return [$from?->setTimezone('UTC'), $to?->setTimezone('UTC')];
    }

    private function applySearch($query, string $term)
    {
        $like = '%'.$term.'%';
        $driver = DB::connection()->getDriverName();
        $operator = $driver === 'pgsql' ? 'ilike' : 'like';

        return $query->where(function ($inner) use ($like, $operator, $driver) {
            $inner->where('resource_label', $operator, $like)
                ->orWhere('action', $operator, $like)
                ->orWhere('resource_id', $operator, $like);

            if ($driver === 'pgsql') {
                $inner->orWhereRaw('ip_address::text ILIKE ?', [$like]);
                $inner->orWhereRaw('metadata::text ILIKE ?', [$like]);
            } else {
                $inner->orWhereRaw('CAST(ip_address AS TEXT) LIKE ?', [$like]);
                $inner->orWhereRaw('CAST(metadata AS TEXT) LIKE ?', [$like]);
            }
        });
    }

}
