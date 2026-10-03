<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Sales\SalesFilterRequest;
use App\Services\Export\MarketplaceExcelExport;
use Symfony\Component\HttpFoundation\Response;

class ExportController extends Controller
{
    /**
     * GET /api/export/excel?from=&to=&sellers=all|1,2&sheets=all|resume,vendeur,...
     */
    public function excel(SalesFilterRequest $request, MarketplaceExcelExport $export): Response
    {
        $sellers = (string) $request->query('sellers', 'all');
        $sellerIds = $sellers === 'all'
            ? null
            : collect(explode(',', $sellers))->map(fn ($s) => (int) trim($s))->filter()->values()->all();

        $sheetsParam = (string) $request->query('sheets', 'all');
        $sheets = $sheetsParam === 'all'
            ? MarketplaceExcelExport::SHEETS
            : array_values(array_intersect(MarketplaceExcelExport::SHEETS, array_map('trim', explode(',', $sheetsParam))));

        [$filename, $binary] = $export->build($request->query('from'), $request->query('to'), $sellerIds ?: null, $sheets);

        return response($binary, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }
}
