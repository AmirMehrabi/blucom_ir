<?php

namespace App\Services\Reporting;

use RuntimeException;
use XMLWriter;
use ZipArchive;

/** Minimal streaming Open XML writer. All strings are literal cells, never formulas. */
class ExcelReportWriter
{
    public function write(string $path, iterable $sheets): void
    {
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Cannot create report workbook.');
        }
        $files = [];
        try {
            $workbook = '<?xml version="1.0" encoding="UTF-8"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>';
            $relations = '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
            $types = '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>';
            $index = 0;
            foreach ($sheets as $name => $rows) {
                $index++;
                $temp = tempnam(dirname($path), basename($path).'.sheet-');
                if ($temp === false) {
                    throw new RuntimeException('Cannot create report worksheet.');
                }
                $files[] = $temp;
                $xml = new XMLWriter;
                if (! $xml->openUri($temp)) {
                    throw new RuntimeException('Cannot open report worksheet.');
                }
                $xml->startDocument('1.0', 'UTF-8');
                $xml->startElement('worksheet');
                $xml->writeAttribute('xmlns', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
                $xml->startElement('sheetViews');
                $xml->startElement('sheetView');
                $xml->writeAttribute('workbookViewId', '0');
                $xml->writeAttribute('rightToLeft', '1');
                $xml->startElement('pane');
                $xml->writeAttribute('ySplit', '1');
                $xml->writeAttribute('topLeftCell', 'A2');
                $xml->writeAttribute('state', 'frozen');
                $xml->endElement();
                $xml->endElement();
                $xml->endElement();
                $xml->startElement('cols');
                $xml->startElement('col');
                $xml->writeAttribute('min', '1');
                $xml->writeAttribute('max', '20');
                $xml->writeAttribute('width', '25');
                $xml->writeAttribute('customWidth', '1');
                $xml->endElement();
                $xml->endElement();
                $xml->startElement('sheetData');
                $count = 0;
                $columns = 1;
                foreach ($rows as $row) {
                    if (++$count > 1048576) {
                        throw new RuntimeException('Report exceeds Excel worksheet row limit.');
                    }
                    $columns = max($columns, count($row));
                    $xml->startElement('row');
                    $xml->writeAttribute('r', (string) $count);
                    foreach (array_values($row) as $i => $value) {
                        $xml->startElement('c');
                        $xml->writeAttribute('r', $this->column($i + 1).$count);
                        if ($count === 1) {
                            $xml->writeAttribute('s', '1');
                        }
                        if (is_int($value) || is_float($value)) {
                            $xml->writeElement('v', (string) $value);
                        } else {
                            $xml->writeAttribute('t', 'inlineStr');
                            $xml->startElement('is');
                            $xml->startElement('t');
                            $xml->writeAttribute('xml:space', 'preserve');
                            $text = preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', (string) $value) ?? '';
                            $xml->text(mb_substr($text, 0, 32767));
                            $xml->endElement();
                            $xml->endElement();
                        }
                        $xml->endElement();
                    }
                    $xml->endElement();
                    $xml->flush();
                }
                $xml->endElement();
                if ($count > 0) {
                    $xml->startElement('autoFilter');
                    $xml->writeAttribute('ref', 'A1:'.$this->column($columns).$count);
                    $xml->endElement();
                }
                $xml->endElement();
                $xml->endDocument();
                $xml->flush();
                unset($xml);
                if (! $zip->addFile($temp, "xl/worksheets/sheet{$index}.xml")) {
                    throw new RuntimeException('Cannot add report worksheet.');
                }
                $safeName = htmlspecialchars((string) $name, ENT_XML1 | ENT_QUOTES, 'UTF-8');
                $workbook .= "<sheet name=\"{$safeName}\" sheetId=\"{$index}\" r:id=\"rId{$index}\"/>";
                $relations .= "<Relationship Id=\"rId{$index}\" Type=\"http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet\" Target=\"worksheets/sheet{$index}.xml\"/>";
                $types .= "<Override PartName=\"/xl/worksheets/sheet{$index}.xml\" ContentType=\"application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml\"/>";
            }
            $relations .= '<Relationship Id="styles" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';
            $types .= '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';
            $zip->addFromString('xl/styles.xml', '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><color rgb="FFFFFFFF"/><sz val="11"/><name val="Calibri"/></font></fonts><fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FF0050D0"/><bgColor indexed="64"/></patternFill></fill></fills><borders count="1"><border/></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="2"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/></cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>');
            $zip->addFromString('xl/workbook.xml', $workbook.'</sheets></workbook>');
            $zip->addFromString('xl/_rels/workbook.xml.rels', $relations.'</Relationships>');
            $zip->addFromString('[Content_Types].xml', $types.'</Types>');
            $zip->addFromString('_rels/.rels', '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
            if (! $zip->close()) {
                throw new RuntimeException('Cannot finalize report workbook.');
            }
        } catch (\Throwable $exception) {
            $zip->close();
            @unlink($path);
            throw $exception;
        } finally {
            foreach ($files as $file) {
                @unlink($file);
            }
        }
    }

    private function column(int $number): string
    {
        $name = '';
        while ($number > 0) {
            $number--;
            $name = chr(65 + $number % 26).$name;
            $number = intdiv($number, 26);
        }

        return $name;
    }
}
