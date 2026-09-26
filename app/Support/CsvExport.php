<?php

namespace App\Support;

use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Shared streamed-CSV download for the superadmin reports — writes rows as
 * they're pulled from the iterable rather than building the whole file in
 * memory first, so exporting a report with a lot of rows (a full season's
 * bet ledger, say) doesn't require holding it all at once.
 */
class CsvExport
{
    /**
     * @param  array<int, string>  $headers
     * @param  iterable<array<int, mixed>>  $rows
     */
    public static function stream(string $filename, array $headers, iterable $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($headers, $rows) {
            $out = fopen('php://output', 'w');

            // Excel opens a plain UTF-8 CSV with peso signs / non-ASCII
            // names as mojibake unless a BOM marks the encoding explicitly.
            fwrite($out, "\xEF\xBB\xBF");

            fputcsv($out, $headers);

            foreach ($rows as $row) {
                fputcsv($out, $row);
            }

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv',
        ]);
    }
}
