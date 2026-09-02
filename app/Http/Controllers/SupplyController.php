<?php

namespace App\Http\Controllers;

use App\Http\Requests\SupplyRequest;
use App\Models\Supplier;
use App\Models\Supply;
use App\Tenancy\TenantStoragePath;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class SupplyController extends Controller
{
    public function create(Supplier $supplier): View
    {
        return view('supplies.create', compact('supplier'));
    }

    public function store(
        SupplyRequest $request,
        Supplier $supplier,
        TenantStoragePath $storagePaths,
    ): RedirectResponse {
        $data = $request->safe()->except('bill_image');

        if ($request->hasFile('bill_image')) {
            $data['bill_image_path'] = $storagePaths->store(
                $request->file('bill_image'),
                'supplier-bills',
            );
        }

        try {
            $supply = $supplier->supplies()->create($data);
        } catch (Throwable $exception) {
            if (isset($data['bill_image_path'])) {
                $storagePaths->delete($data['bill_image_path'], 'supplier-bills');
            }

            throw $exception;
        }

        return to_route('suppliers.supplies.show', [$supplier, $supply])->with('status', 'Supply entry saved.');
    }

    public function show(Supplier $supplier, Supply $supply): View
    {
        $this->ensureSupplierOwnsSupply($supplier, $supply);

        return view('supplies.show', [
            'supplier' => $supplier,
            'supply' => $supply->load(['payments' => fn ($query) => $query->with('user:id,name')->latest('paid_at')->latest('id')]),
        ]);
    }

    public function bill(
        Supplier $supplier,
        Supply $supply,
        TenantStoragePath $storagePaths,
    ): StreamedResponse {
        $this->ensureSupplierOwnsSupply($supplier, $supply);
        $path = $storagePaths->readablePath($supply->bill_image_path, 'supplier-bills');
        abort_if($path === null, 404);

        return Storage::disk('local')->response(
            $path,
            headers: ['X-Content-Type-Options' => 'nosniff'],
        );
    }

    private function ensureSupplierOwnsSupply(Supplier $supplier, Supply $supply): void
    {
        abort_unless($supply->supplier_id === $supplier->id, 404);
    }
}
