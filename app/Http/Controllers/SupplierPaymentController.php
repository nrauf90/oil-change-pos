<?php

namespace App\Http\Controllers;

use App\Actions\RecordSupplierPayment;
use App\Http\Requests\SupplierPaymentRequest;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use App\Models\Supply;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class SupplierPaymentController extends Controller
{
    public function store(
        SupplierPaymentRequest $request,
        Supplier $supplier,
        Supply $supply,
        RecordSupplierPayment $recordPayment,
    ): RedirectResponse {
        $this->ensureSupplierOwnsSupply($supplier, $supply);
        $data = $request->safe()->except('receipt_image');

        if ($request->hasFile('receipt_image')) {
            $data['receipt_image_path'] = $request->file('receipt_image')->store('supplier-payment-receipts');
        }

        try {
            $recordPayment($supply, $request->user(), $data);
        } catch (Throwable $exception) {
            if (isset($data['receipt_image_path'])) {
                Storage::disk('local')->delete($data['receipt_image_path']);
            }

            throw $exception;
        }

        return to_route('suppliers.supplies.show', [$supplier, $supply])->with('status', 'Payment recorded.');
    }

    public function receipt(Supplier $supplier, Supply $supply, SupplierPayment $payment): StreamedResponse
    {
        $this->ensureSupplierOwnsSupply($supplier, $supply);
        abort_unless($payment->supply_id === $supply->id, 404);
        abort_if(blank($payment->receipt_image_path) || ! Storage::disk('local')->exists($payment->receipt_image_path), 404);

        return Storage::disk('local')->response(
            $payment->receipt_image_path,
            headers: ['X-Content-Type-Options' => 'nosniff'],
        );
    }

    private function ensureSupplierOwnsSupply(Supplier $supplier, Supply $supply): void
    {
        abort_unless($supply->supplier_id === $supplier->id, 404);
    }
}
