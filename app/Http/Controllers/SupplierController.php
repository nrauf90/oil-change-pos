<?php

namespace App\Http\Controllers;

use App\Http\Requests\SupplierRequest;
use App\Models\Supplier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SupplierController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): View
    {
        $search = trim($request->string('q')->toString());

        return view('suppliers.index', [
            'suppliers' => Supplier::query()
                ->when($search !== '', fn ($query) => $query->where(function ($nested) use ($search): void {
                    $nested->where('name', 'like', '%'.addcslashes($search, '%_\\').'%')
                        ->orWhere('contact_person', 'like', '%'.addcslashes($search, '%_\\').'%')
                        ->orWhere('phone', 'like', '%'.addcslashes($search, '%_\\').'%');
                }))
                ->with(['supplies.payments'])
                ->orderBy('name')
                ->paginate(20)
                ->withQueryString(),
            'search' => $search,
        ]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create(): View
    {
        return view('suppliers.create');
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(SupplierRequest $request): RedirectResponse
    {
        $supplier = Supplier::create($request->validated());

        return to_route('suppliers.show', $supplier)->with('status', 'Supplier saved.');
    }

    /**
     * Display the specified resource.
     */
    public function show(Supplier $supplier): View
    {
        return view('suppliers.show', [
            'supplier' => $supplier->load(['supplies' => fn ($query) => $query->with('payments')->latest('received_at')->latest('id')]),
        ]);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Supplier $supplier): View
    {
        return view('suppliers.edit', compact('supplier'));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(SupplierRequest $request, Supplier $supplier): RedirectResponse
    {
        $supplier->update($request->validated());

        return to_route('suppliers.show', $supplier)->with('status', 'Supplier details updated.');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Supplier $supplier): RedirectResponse
    {
        if ($supplier->supplies()->exists()) {
            return back()->withErrors([
                'supplier' => 'This supplier has financial history and cannot be deleted.',
            ]);
        }

        $supplier->delete();

        return to_route('suppliers.index')->with('status', 'Supplier deleted.');
    }
}
