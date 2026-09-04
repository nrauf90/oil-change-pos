<?php

namespace App\Http\Controllers;

use App\Actions\CompleteOrder;
use App\Actions\SaveDraftOrder;
use App\Exceptions\StaleDraftOrderException;
use App\Http\Requests\DraftOrderRequest;
use App\Models\Order;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class OrderController extends Controller
{
    /**
     * Every open bill, oldest first — the workshop works through bays in the
     * order the cars arrived. Drafts are visible to the whole counter, not
     * only their author: the person who opened a bill may be at lunch when
     * the car is ready.
     */
    public function index(): View
    {
        return view('orders.index', [
            'orders' => Order::query()
                ->drafts()
                ->with('user:id,name')
                ->withSum('lines as lines_total', 'manually_charged_price')
                ->withCount('lines')
                ->orderBy('created_at')
                ->paginate(25),
        ]);
    }

    public function store(DraftOrderRequest $request, SaveDraftOrder $saveDraft): RedirectResponse
    {
        $order = $saveDraft(
            $request->orderPayload(),
            $request->lines(),
            $request->user(),
        );

        return to_route('orders.index')
            ->with('status', sprintf('Bill "%s" saved as a draft.', $order->displayLabel()));
    }

    public function update(
        DraftOrderRequest $request,
        Order $order,
        SaveDraftOrder $saveDraft,
        CompleteOrder $completeOrder,
    ): RedirectResponse {
        $this->ensureEditable($order);

        try {
            $saveDraft(
                $request->orderPayload(),
                $request->lines(),
                $request->user(),
                $order,
                $request->submittedVersion(),
            );
        } catch (StaleDraftOrderException $exception) {
            return back()->withInput()->withErrors(['version' => $exception->getMessage()]);
        }

        if ($request->wantsCompletion()) {
            return $this->completeAndRedirect($order->refresh(), $completeOrder);
        }

        return to_route('orders.index')
            ->with('status', sprintf('Bill "%s" updated.', $order->refresh()->displayLabel()));
    }

    /**
     * Completing is the boundary: the bill becomes a Sale, stock moves, and the
     * invoice becomes reachable. Nothing before this point can be printed.
     */
    public function complete(Order $order, CompleteOrder $completeOrder): RedirectResponse
    {
        $this->ensureEditable($order);

        return $this->completeAndRedirect($order, $completeOrder);
    }

    private function completeAndRedirect(Order $order, CompleteOrder $completeOrder): RedirectResponse
    {
        abort_if($order->lines()->doesntExist(), 422, 'An empty bill cannot be completed.');

        $sale = $completeOrder($order);

        return to_route('sales.show', $sale)
            ->with('status', 'Bill completed. Invoice '.$sale->invoice_number.' is ready.');
    }

    public function destroy(Order $order): RedirectResponse
    {
        $this->ensureEditable($order);

        $label = $order->displayLabel();
        $order->delete();

        return to_route('orders.index')->with('status', sprintf('Draft "%s" deleted.', $label));
    }

    /** A completed bill is a financial record and is no longer the counter's to change. */
    private function ensureEditable(Order $order): void
    {
        abort_unless($order->isDraft(), 404);
    }
}
