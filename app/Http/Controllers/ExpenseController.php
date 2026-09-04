<?php

namespace App\Http\Controllers;

use App\Actions\AttachExpenseReceipts;
use App\Enums\ExpenseCategory;
use App\Http\Requests\ExpenseRequest;
use App\Models\Expense;
use App\Models\ExpenseReceipt;
use App\Support\CashDrawer;
use App\Tenancy\TenantStoragePath;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExpenseController extends Controller
{
    public function index(Request $request): View
    {
        $filters = CashDrawer::readFilters($request);

        $matching = fn (): Builder => Expense::query()
            ->when($filters['from'] !== null, fn (Builder $q) => $q->where('spent_at', '>=', $filters['from']))
            ->when($filters['to'] !== null, fn (Builder $q) => $q->where('spent_at', '<=', $filters['to']))
            ->ofCategory($filters['category']);

        return view('expenses.index', [
            'expenses' => $matching()->with('user')->withCount('receipts')
                ->orderByDesc('spent_at')->orderByDesc('id')
                ->paginate(25)->withQueryString(),
            // The running total covers the whole filtered set, not just this page.
            'filteredTotal' => CashDrawer::sum($matching()->pluck('amount')),
            'filteredCount' => $matching()->count(),
            'categories' => ExpenseCategory::cases(),
            'filters' => $filters,
            'isFiltered' => $filters['from'] !== null || $filters['to'] !== null || $filters['category'] !== null,
        ]);
    }

    public function create(): View
    {
        return view('expenses.create', ['categories' => ExpenseCategory::cases()]);
    }

    public function store(ExpenseRequest $request, AttachExpenseReceipts $attachReceipts): RedirectResponse
    {
        $expense = new Expense($request->payload());
        // Logged By comes from the session, never from the payload.
        $expense->user()->associate($request->user());
        $expense->save();

        $attachReceipts($expense, $request->file('receipts', []), $request->user());

        return to_route('expenses.index')->with('status', $this->confirmation($expense, 'logged'));
    }

    public function edit(Expense $expense): View
    {
        return view('expenses.edit', [
            'expense' => $expense->load('user', 'receipts'),
            'categories' => ExpenseCategory::cases(),
        ]);
    }

    /**
     * Only the four validated fields move. `user_id` is untouched, so an edit
     * never rewrites who originally took the money out of the drawer.
     */
    public function update(
        ExpenseRequest $request,
        Expense $expense,
        AttachExpenseReceipts $attachReceipts,
    ): RedirectResponse {
        $expense->update($request->payload());

        // Receipts accumulate. An edit adds proof, it never quietly drops what
        // is already filed — removing one is its own deliberate action.
        $attachReceipts($expense, $request->file('receipts', []), $request->user());

        return to_route('expenses.index')->with('status', $this->confirmation($expense, 'updated'));
    }

    public function destroy(Expense $expense, AttachExpenseReceipts $attachReceipts): RedirectResponse
    {
        // Files go before the rows, which the cascading foreign key takes.
        $attachReceipts->purge($expense);
        $expense->delete();

        return to_route('expenses.index')->with('status', $this->confirmation($expense, 'deleted'));
    }

    /**
     * Streams a receipt back from the private disk. Nothing is ever served by
     * URL, so this route is the only way in — and it checks the receipt really
     * belongs to the expense in the path before it opens anything.
     */
    public function receipt(
        Expense $expense,
        ExpenseReceipt $receipt,
        TenantStoragePath $storagePaths,
    ): StreamedResponse {
        $this->ensureExpenseOwnsReceipt($expense, $receipt);
        $path = $storagePaths->readablePath($receipt->path, AttachExpenseReceipts::DIRECTORY);
        abort_if($path === null, 404);

        return Storage::disk('local')->response(
            $path,
            $receipt->original_name,
            // A receipt is proof, not a page: never let a browser sniff or run it.
            ['X-Content-Type-Options' => 'nosniff', 'Content-Type' => $receipt->mime_type],
        );
    }

    public function destroyReceipt(
        Expense $expense,
        ExpenseReceipt $receipt,
        AttachExpenseReceipts $attachReceipts,
    ): RedirectResponse {
        $this->ensureExpenseOwnsReceipt($expense, $receipt);
        $attachReceipts->forget($receipt);

        return to_route('expenses.edit', $expense)->with('status', 'Receipt removed.');
    }

    private function ensureExpenseOwnsReceipt(Expense $expense, ExpenseReceipt $receipt): void
    {
        abort_unless($receipt->expense_id === $expense->id, 404);
    }

    private function confirmation(Expense $expense, string $verb): string
    {
        return sprintf(
            '%s expense of %s %s.',
            $expense->category->label(),
            number_format((float) $expense->amount, 2),
            $verb,
        );
    }
}
