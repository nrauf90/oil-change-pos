<?php

namespace App\Http\Controllers;

use App\Actions\RecordSale;
use App\Http\Requests\StoreSaleRequest;
use App\Models\Sale;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

class SaleController extends Controller
{
    public function index(Request $request): View
    {
        // ?q[]=… hands us an array. Casting that to a string warns and then
        // searches for the literal "Array", so anything but a string is no search.
        $search = is_string($q = $request->query('q')) ? $q : '';

        $sales = Sale::query()
            ->search($search)
            ->with('cashier:id,name')
            ->withCount('lines')
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        return view('sales.index', [
            'sales' => $sales,
            'search' => $search,
        ]);
    }

    public function store(StoreSaleRequest $request, RecordSale $recordSale): RedirectResponse
    {
        $sale = $recordSale($request->validated());

        return to_route('sales.show', $sale)
            ->with('status', "Invoice {$sale->invoice_number} saved.");
    }

    public function show(Sale $sale): View
    {
        return view('sales.show', ['sale' => $sale->load(['lines', 'cashier:id,name'])]);
    }

    public function pdf(Sale $sale): Response
    {
        $pdf = Pdf::loadView('sales.pdf', [
            'sale' => $sale->load(['lines', 'cashier:id,name']),
        ])->setPaper('a4');

        return $pdf->download("{$sale->invoice_number}.pdf");
    }

    public function destroy(Sale $sale): RedirectResponse
    {
        $invoice = $sale->invoice_number;
        $sale->delete();

        return to_route('sales.index')->with('status', "Invoice {$invoice} deleted.");
    }
}
