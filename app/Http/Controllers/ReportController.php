<?php

namespace App\Http\Controllers;

use App\Support\SalesReport;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ReportController extends Controller
{
    public function index(Request $request): View
    {
        $period = $request->query('period');

        $report = new SalesReport(is_string($period) ? $period : null);

        return view('reports.index', $report->toViewData());
    }
}
