<?php
// Minimal hand-rolled .xlsx (OOXML) writer - no PhpSpreadsheet/composer,
// consistent with items_import.php's hand-rolled XLSX *reader* using the
// same ZipArchive extension. Writes the smallest set of parts a real
// spreadsheet app (Excel/LibreOffice/Google Sheets) needs: content types,
// root + workbook relationships, workbook.xml and one worksheet - using
// inline strings (t="inlineStr") so no separate sharedStrings.xml part is
// needed.

function xlsx_col_letter($i) {
    $s = '';
    $i++;
    while ($i > 0) {
        $m = ($i - 1) % 26;
        $s = chr(65 + $m) . $s;
        $i = intdiv($i - 1, 26);
    }
    return $s;
}

function xlsx_x($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

/** $rows is a list of rows, each row a list of cell values (numbers or strings). Returns raw .xlsx bytes. */
function xlsx_build($rows) {
    $sheetXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
        '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
    foreach ($rows as $ri => $row) {
        $r = $ri + 1;
        $sheetXml .= '<row r="' . $r . '">';
        foreach (array_values($row) as $ci => $val) {
            $ref = xlsx_col_letter($ci) . $r;
            // Leading-zero numeric strings (invoice/phone numbers like "0123")
            // are kept as text so Excel doesn't silently strip the zero.
            $isNum = is_numeric($val) && !preg_match('/^0[0-9]/', (string)$val);
            if ($isNum) {
                $sheetXml .= '<c r="' . $ref . '"><v>' . (0 + $val) . '</v></c>';
            } else {
                $sheetXml .= '<c r="' . $ref . '" t="inlineStr"><is><t xml:space="preserve">' . xlsx_x($val) . '</t></is></c>';
            }
        }
        $sheetXml .= '</row>';
    }
    $sheetXml .= '</sheetData></worksheet>';

    $contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
        '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">' .
        '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>' .
        '<Default Extension="xml" ContentType="application/xml"/>' .
        '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>' .
        '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>' .
        '</Types>';

    $rootRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
        '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' .
        '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>' .
        '</Relationships>';

    $workbook = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
        '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">' .
        '<sheets><sheet name="Report" sheetId="1" r:id="rId1"/></sheets></workbook>';

    $workbookRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
        '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' .
        '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>' .
        '</Relationships>';

    $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
    $zip = new ZipArchive();
    $zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('[Content_Types].xml', $contentTypes);
    $zip->addFromString('_rels/.rels', $rootRels);
    $zip->addFromString('xl/workbook.xml', $workbook);
    $zip->addFromString('xl/_rels/workbook.xml.rels', $workbookRels);
    $zip->addFromString('xl/worksheets/sheet1.xml', $sheetXml);
    $zip->close();
    $bytes = file_get_contents($tmp);
    unlink($tmp);
    return $bytes;
}
