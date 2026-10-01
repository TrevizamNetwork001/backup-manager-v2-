<?php

namespace App\Services;

class DocumentPdfExporter
{
    /** @param list<array{heading: string, lines: list<string>}> $blocks */
    public function render(string $title, string $subtitle, array $blocks): string
    {
        $pages = [];
        $commands = [];
        $y = 797;
        $write = function (string $value, bool $bold = false) use (&$commands, &$y, &$pages): void {
            foreach ($this->wrap($value) as $line) {
                if ($y < 52) {
                    $pages[] = implode("\n", $commands)."\n";
                    $commands = [];
                    $y = 797;
                }
                $font = $bold ? 'F2' : 'F1';
                $size = $bold ? 12 : 10;
                $commands[] = 'BT /'.$font.' '.$size.' Tf 45 '.$y.' Td ('.$this->escape($line).') Tj ET';
                $y -= $bold ? 20 : 15;
            }
        };

        $write($title, true);
        $write($subtitle);
        $y -= 12;
        if ($blocks === []) {
            $write('Nenhum equipamento encontrado.');
        }
        foreach ($blocks as $block) {
            $blockHeight = count($this->wrap($block['heading'])) * 20 + 10;
            foreach ($block['lines'] as $line) {
                $blockHeight += count($this->wrap($line)) * 15;
            }
            if ($y < 115 || ($blockHeight <= 745 && $y - $blockHeight < 52)) {
                $pages[] = implode("\n", $commands)."\n";
                $commands = [];
                $y = 797;
            }
            $write($block['heading'], true);
            foreach ($block['lines'] as $line) {
                $write($line);
            }
            $y -= 10;
        }
        $pages[] = implode("\n", $commands)."\n";

        $objects = [
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            3 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',
            4 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>',
        ];
        $kids = [];
        foreach ($pages as $index => $page) {
            $pageId = 5 + $index * 2;
            $contentId = $pageId + 1;
            $kids[] = $pageId.' 0 R';
            $objects[$pageId] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents '.$contentId.' 0 R >>';
            $objects[$contentId] = '<< /Length '.strlen($page).' >>'."\nstream\n".$page.'endstream';
        }
        $objects[2] = '<< /Type /Pages /Kids ['.implode(' ', $kids).'] /Count '.count($pages).' >>';
        ksort($objects);

        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [0];
        foreach ($objects as $id => $body) {
            $offsets[$id] = strlen($pdf);
            $pdf .= $id." 0 obj\n".$body."\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= 'xref'."\n0 ".(count($objects) + 1)."\n0000000000 65535 f \n";
        for ($id = 1; $id <= count($objects); $id++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$id]);
        }
        $pdf .= 'trailer'."\n".'<< /Size '.(count($objects) + 1).' /Root 1 0 R >>'."\nstartxref\n".$xref."\n%%EOF\n";

        return $pdf;
    }

    /** @return list<string> */
    private function wrap(string $value): array
    {
        $encoded = iconv('UTF-8', 'Windows-1252//TRANSLIT', $value);
        $encoded = preg_replace('/[\x00-\x1f\x7f]/', ' ', $encoded === false ? '' : $encoded);

        return explode("\n", wordwrap($encoded, 92, "\n", true));
    }

    private function escape(string $value): string
    {
        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $value);
    }
}
