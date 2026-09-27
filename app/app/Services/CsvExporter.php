<?php

namespace App\Services;

use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Single CSV export primitive for every report (FEATURES-FINAL-1, Part D/E/F
 * — avoid duplicating export logic per report, matching Part J).
 *
 * Two things V1's exporter got wrong, fixed here (see docs/REPORTS.md,
 * "Comparação com V1"): it built the entire result set in memory before
 * writing (`stream()` here writes row-by-row against a StreamedResponse, so
 * memory stays flat regardless of row count — feed it a LazyCollection or
 * generator, never a materialized array for large exports); and it had no
 * CSV-injection protection at all (`neutralize()` here prefixes a leading
 * `'` on any cell starting with `=`, `+`, `-`, or `@`, per OWASP guidance, so
 * a spreadsheet application never interprets an exported field as a formula).
 */
class CsvExporter
{
    private const RISKY_PREFIXES = ['=', '+', '-', '@'];

    /**
     * @param  list<string>  $headers
     * @param  iterable<array<int, mixed>>  $rows  Each row must have the same
     *                                             column count/order as $headers.
     * @param  ?callable(int):void  $onComplete  Invoked with the final row count
     *                                           once every row has been written —
     *                                           the only point a caller can learn
     *                                           the real count when $rows is a lazy
     *                                           generator (needed for the
     *                                           report.exported audit event, since
     *                                           the controller returns before the
     *                                           StreamedResponse callback runs).
     */
    public function stream(string $filename, array $headers, iterable $rows, ?callable $onComplete = null): StreamedResponse
    {
        $response = new StreamedResponse(function () use ($headers, $rows, $onComplete) {
            $handle = fopen('php://output', 'w');
            if ($handle === false) {
                throw new RuntimeException('Não foi possível abrir a saída CSV.');
            }
            $count = 0;
            try {
                // UTF-8 BOM so Excel opens accented characters correctly.
                if (fwrite($handle, "\xEF\xBB\xBF") !== 3 || fputcsv($handle, $headers) === false) {
                    throw new RuntimeException('Não foi possível escrever o cabeçalho CSV.');
                }
                foreach ($rows as $row) {
                    if (fputcsv($handle, array_map($this->neutralize(...), $row)) === false) {
                        throw new RuntimeException('Não foi possível escrever uma linha CSV.');
                    }
                    $count++;
                }
            } catch (\Throwable $exception) {
                fclose($handle);
                throw $exception;
            }

            if (! fclose($handle)) {
                throw new RuntimeException('Não foi possível concluir a saída CSV.');
            }
            if ($onComplete) {
                $onComplete($count);
            }
        });

        $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $response->headers->set('Content-Disposition', 'attachment; filename="'.$filename.'"');
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }

    private function neutralize(mixed $value): mixed
    {
        if (! is_string($value) || $value === '') {
            return $value;
        }

        return in_array($value[0], self::RISKY_PREFIXES, true) ? "'".$value : $value;
    }
}
