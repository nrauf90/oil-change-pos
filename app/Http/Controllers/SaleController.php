<?php

namespace App\Http\Controllers;

use App\Actions\DeleteSale;
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
        // The invoice template pulls nothing off disk, so dompdf gets a chroot
        // narrower than the default project root — which would otherwise put
        // .env and the whole source tree inside its reach.
        $pdf = Pdf::loadView('sales.pdf', [
            'sale' => $sale->load(['lines', 'cashier:id,name']),
        ])->setPaper('a4')->setOption('chroot', public_path());

        return $pdf->download("{$sale->invoice_number}.pdf");
    }

    public function destroy(Sale $sale, DeleteSale $deleteSale): RedirectResponse
    {
        $invoice = $sale->invoice_number;

        // Deleting a bill means it never happened, so what it drew goes back
        // on the shelf — otherwise stock drifts down with every mis-keyed
        // invoice and the low-stock alert fires against goods still present.
        $deleteSale->handle($sale);

        return to_route('sales.index')->with('status', "Invoice {$invoice} deleted, and its stock returned.");
    }
}
