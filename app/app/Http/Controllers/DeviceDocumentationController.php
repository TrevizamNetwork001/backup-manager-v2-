<?php

namespace App\Http\Controllers;

use App\Models\Site;
use App\Reports\DeviceDocumentationReport;
use App\Services\AuditEvents;
use App\Services\CsvExporter;
use App\Services\DocumentPdfExporter;
use App\Services\InstanceTimezone;
use App\Support\Rbac;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DeviceDocumentationController extends Controller
{
    private const HEADERS = [
        'Site / POP', 'Código do site', 'Local', 'Equipamento', 'Hostname', 'IP de gerenciamento',
        'Fabricante', 'Modelo', 'Versão', 'Tipo', 'Função', 'Status do equipamento',
        'Nome do acesso', 'Método de acesso', 'Usuário', 'Porta', 'Status do acesso', 'Políticas de backup',
    ];

    public function index(Request $request, DeviceDocumentationReport $report): View
    {
        $this->authorizeAdmin($request);
        $siteId = $this->siteId($request);
        $sites = Site::query()->orderBy('name')->get(['id', 'name', 'code']);
        $devices = $report->devices($siteId);
        $popCount = $devices->pluck('site_id')->unique()->count();

        return view('reports.documentation', compact('sites', 'siteId', 'devices', 'popCount'));
    }

    public function csv(Request $request, DeviceDocumentationReport $report, CsvExporter $csv, AuditEvents $audit): StreamedResponse
    {
        $this->authorizeAdmin($request);
        $this->authorize('reports.export');
        $siteId = $this->siteId($request);
        $rows = $report->csvRows($report->devices($siteId));

        $response = $csv->stream('documentacao-equipamentos.csv', self::HEADERS, $rows,
            fn (int $count) => $this->audit($audit, $siteId, 'csv', $count));
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }

    public function pdf(Request $request, DeviceDocumentationReport $report, DocumentPdfExporter $pdf, AuditEvents $audit, InstanceTimezone $timezone): Response
    {
        $this->authorizeAdmin($request);
        $this->authorize('reports.export');
        $siteId = $this->siteId($request);
        $devices = $report->devices($siteId);
        $site = $siteId ? Site::query()->findOrFail($siteId) : null;
        $popCount = $devices->pluck('site_id')->unique()->count();
        $subtitle = ($site ? 'Site / POP: '.$site->name : 'Todos os Sites / POPs').
            ' | POPs: '.$popCount.' | Equipamentos: '.$devices->count().
            ' | Gerado em '.$timezone->format(now(), 'd/m/Y H:i');
        $content = $pdf->render('Documentação de equipamentos', $subtitle, $report->pdfBlocks($devices, true));
        $this->audit($audit, $siteId, 'pdf', $devices->count(), true);

        return response($content, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="documentacao-equipamentos.pdf"',
            'Cache-Control' => 'no-store, private',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function authorizeAdmin(Request $request): void
    {
        abort_unless($request->user()?->hasRole(Rbac::ROLE_ADMIN), 403);
    }

    private function siteId(Request $request): ?int
    {
        $validated = $request->validate(['site_id' => ['nullable', 'integer', 'exists:sites,id']]);

        return isset($validated['site_id']) ? (int) $validated['site_id'] : null;
    }

    private function audit(AuditEvents $audit, ?int $siteId, string $format, int $count, bool $containsSecrets = false): void
    {
        if (! Schema::hasTable('audit_events')) {
            return;
        }
        $audit->record('report.exported', 'report', 'documentation', 'documentation', 'success', [
            'report_type' => 'documentation', 'filters' => array_filter(['site_id' => $siteId]),
            'row_count' => $count, 'format' => $format, 'contains_secrets' => $containsSecrets,
        ], auth()->id(), request()->ip());
    }
}
