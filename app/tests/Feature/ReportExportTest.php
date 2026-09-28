<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\BackupExecution;
use App\Models\User;
use App\Reports\ExecutionReportQuery;
use App\Services\CsvExporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\LazyCollection;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class ReportExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_on_complete_receives_the_real_count_from_a_lazy_generator_without_materializing_it(): void
    {
        $generated = 0;
        $completed = null;
        $baseMemory = memory_get_usage(true);
        $maxGrowth = 0;
        $rows = (function () use (&$generated, &$maxGrowth, $baseMemory) {
            for ($i = 0; $i < 50000; $i++) {
                $generated++;
                if ($i % 1000 === 0) {
                    $maxGrowth = max($maxGrowth, memory_get_usage(true) - $baseMemory);
                }
                yield [$i, 'valor'];
            }
        })();

        $response = app(CsvExporter::class)->stream('large.csv', ['Número', 'Valor'], $rows,
            function (int $count) use (&$completed): void {
                $completed = $count;
            });

        $this->assertSame(0, $generated);
        $this->assertNull($completed);
        ob_start(static fn (string $chunk): string => '', 8192);
        try {
            $response->sendContent();
        } finally {
            ob_end_clean();
        }

        $this->assertSame(50000, $generated);
        $this->assertSame(50000, $completed);
        $this->assertLessThan(8 * 1024 * 1024, $maxGrowth);
    }

    public function test_empty_export_calls_on_complete_with_zero_rows(): void
    {
        $completed = null;
        $response = app(CsvExporter::class)->stream('empty.csv', ['Coluna'], (function () {
            if (false) {
                yield ['nunca'];
            }
        })(), function (int $count) use (&$completed): void {
            $completed = $count;
        });

        ob_start();
        try {
            $response->sendContent();
            $content = ob_get_contents();
        } finally {
            ob_end_clean();
        }

        $this->assertSame(0, $completed);
        $this->assertSame("\xEF\xBB\xBFColuna\n", $content);
    }

    public function test_csv_formula_prefixes_are_neutralized_during_streaming(): void
    {
        $rows = (function () {
            yield ['=1+1', '+SUM(A1:A2)', '-1', '@cmd'];
        })();
        $response = app(CsvExporter::class)->stream('safe.csv', ['A', 'B', 'C', 'D'], $rows);

        ob_start();
        try {
            $response->sendContent();
            $content = ob_get_contents();
        } finally {
            ob_end_clean();
        }

        $this->assertSame(["'=1+1", "'+SUM(A1:A2)", "'-1", "'@cmd"],
            str_getcsv(explode("\n", $content)[1]));
    }

    public function test_generator_exception_skips_on_complete_and_success_audit_even_after_a_partial_row(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $execution = new BackupExecution(['status' => 'succeeded', 'attempt' => 1]);
        $execution->created_at = now();
        $execution->setRelation('device', null);
        $execution->setRelation('backupPolicy', null);
        $execution->setRelation('artifact', null);

        $query = Mockery::mock(ExecutionReportQuery::class)->makePartial();
        $query->shouldReceive('exportRows')->once()->andReturn(LazyCollection::make(function () use ($execution) {
            yield $execution;
            throw new RuntimeException('Falha durante o stream');
        }));
        $this->app->instance(ExecutionReportQuery::class, $query);

        $response = $this->get(route('reports.executions.export'))->assertOk();
        ob_start();
        try {
            $response->sendContent();
            $this->fail('A falha do gerador deveria interromper o stream.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Falha durante o stream', $exception->getMessage());
            $this->assertStringContainsString('Concluído', ob_get_contents());
        } finally {
            ob_end_clean();
        }

        $this->assertSame(0, AuditEvent::query()->where('action', 'report.exported')->count());
    }

    public function test_each_empty_report_audits_zero_only_after_its_stream_finishes(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $reports = [
            'executions' => 'reports.executions.export',
            'devices' => 'reports.devices.export',
            'artifacts' => 'reports.artifacts.export',
            'failures' => 'reports.failures.export',
            'ftp' => 'reports.ftp.export',
        ];

        foreach ($reports as $type => $route) {
            $before = AuditEvent::query()->where('action', 'report.exported')->count();
            $response = $this->get(route($route))->assertOk();
            $this->assertSame($before, AuditEvent::query()->where('action', 'report.exported')->count(), $type);
            ob_start();
            try {
                $response->sendContent();
                $content = ob_get_contents();
            } finally {
                ob_end_clean();
            }

            $event = AuditEvent::query()->where('action', 'report.exported')
                ->where('resource_id', $type)->sole();
            $this->assertSame(0, $event->metadata['row_count'], $type);
            $this->assertSame('success', $event->result);
            $this->assertStringNotContainsString('senha-super-secreta', $content);
            $this->assertStringNotContainsString('senha-super-secreta', json_encode($event->metadata));
        }
    }
}
