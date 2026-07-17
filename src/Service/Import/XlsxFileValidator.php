<?php

declare(strict_types=1);

namespace App\Service\Import;

final class XlsxFileValidator
{
    public function assertValid(string $filePath): void
    {
        if (!is_file($filePath) || filesize($filePath) < 100) {
            throw new \RuntimeException('Atsisiųstas LEA failas yra tuščias arba per mažas.');
        }

        $handle = fopen($filePath, 'rb');
        $signature = $handle !== false ? fread($handle, 2) : false;
        if (is_resource($handle)) {
            fclose($handle);
        }

        if ($signature !== 'PK') {
            throw new \RuntimeException('Atsisiųstas LEA turinys nėra XLSX failas.');
        }

        if (!class_exists(\ZipArchive::class)) {
            return;
        }

        $zip = new \ZipArchive();
        if ($zip->open($filePath) !== true) {
            throw new \RuntimeException('Atsisiųsto XLSX archyvo nepavyko atidaryti.');
        }

        $hasWorkbook = $zip->locateName('xl/workbook.xml') !== false;
        $zip->close();
        if (!$hasWorkbook) {
            throw new \RuntimeException('Atsisiųstame archyve nėra XLSX darbaknygės.');
        }
    }
}
