<?php

namespace App\Http\Controllers;

use App\Enums\ExpenseCategory;
use App\Http\Requests\ExpenseRequest;
use App\Models\Expense;
use App\Support\CashDrawer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

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
            'expenses' => $matching()->with('user')
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

    public function store(ExpenseRequest $request): RedirectResponse
    {
        $expense = new Expense($request->payload());
        // Logged By comes from the session, never from the payload.
        $expense->user()->associate($request->user());
        $expense->save();

        return to_route('expenses.index')->with('status', $this->confirmation($expense, 'logged'));
    }

    public function edit(Expense $expense): View
    {
        return view('expenses.edit', [
            'expense' => $expense->load('user'),
            'categories' => ExpenseCategory::cases(),
        ]);
    }

    /**
     * Only the four validated fields move. `user_id` is untouched, so an edit
     * never rewrites who originally took the money out of the drawer.
     */
    public function update(ExpenseRequest $request, Expense $expense): RedirectResponse
    {
        $expense->update($request->payload());

        return to_route('expenses.index')->with('status', $this->confirmation($expense, 'updated'));
    }

    public function destroy(Expense $expense): RedirectResponse
    {
        $expense->delete();

        return to_route('expenses.index')->with('status', $this->confirmation($expense, 'deleted'));
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
