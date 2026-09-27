<?php

namespace App\Http\Controllers;

use App\Models\AuditEvent;
use App\Models\User;
use App\Support\ReportPeriod;
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
            'period' => ['nullable', Rule::in(ReportPeriod::OPTIONS)],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'actor' => ['nullable', 'string', 'max:20'],
            'action' => ['nullable', 'string', 'max:100'],
            'resource_type' => ['nullable', 'string', 'max:100'],
            'result' => ['nullable', 'string', 'max:30'],
            'q' => ['nullable', 'string', 'max:255'],
        ]);

        [$from, $to] = ReportPeriod::resolve($filters['period'] ?? null, $filters['date_from'] ?? null, $filters['date_to'] ?? null);

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

        $since24h = CarbonImmutable::now('UTC')->subDay();
        $failedAuth = AuditEvent::query()->where('action', 'auth.login_failed');
        $security = [
            'failed_10m' => (clone $failedAuth)->where('created_at', '>=', CarbonImmutable::now('UTC')->subMinutes(10))->count(),
            'failed_24h' => (clone $failedAuth)->where('created_at', '>=', $since24h)->count(),
            'distinct_ips' => (clone $failedAuth)->where('created_at', '>=', $since24h)
                ->whereNotNull('ip_address')->distinct()->count('ip_address'),
            'top_ips' => (clone $failedAuth)->where('created_at', '>=', $since24h)
                ->select('ip_address')->selectRaw('COUNT(*) as attempts, MAX(created_at) as last_at')
                ->groupBy('ip_address')->orderByDesc('attempts')->limit(5)->get(),
            'top_accounts' => (clone $failedAuth)->where('created_at', '>=', $since24h)
                ->select('resource_label')->selectRaw('COUNT(*) as attempts, COUNT(DISTINCT ip_address) as ips, MAX(created_at) as last_at')
                ->groupBy('resource_label')->orderByDesc('attempts')->limit(5)->get(),
            'recent' => AuditEvent::query()->whereIn('action', ['auth.login', 'auth.login_failed'])
                ->orderByDesc('id')->limit(20)->get(),
        ];

        return view('audit.index', compact('events', 'filters', 'actions', 'resourceTypes', 'results', 'users', 'security'));
    }

    public function show(AuditEvent $auditEvent): View
    {
        $this->authorize('audit.view');
        $auditEvent->load('actor:id,name,email');

        return view('audit.show', compact('auditEvent'));
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
