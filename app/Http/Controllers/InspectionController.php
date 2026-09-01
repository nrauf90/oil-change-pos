<?php

namespace App\Http\Controllers;

use App\Enums\InspectionPoint;
use App\Enums\InspectionStatus;
use App\Http\Requests\InspectionRequest;
use App\Models\Inspection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Multi-point inspection sheets — the one thing on the workshop floor a
 * technician writes rather than reads.
 */
class InspectionController extends Controller
{
    public function index(Request $request): View
    {
        // ?plate[]=… hands us an array. Casting that to a string warns and then
        // filters on the literal "Array", so anything but a string is no filter.
        $plate = trim(is_string($p = $request->query('plate')) ? $p : '');

        return view('inspections.index', [
            'plate' => $plate,
            'inspections' => Inspection::query()
                ->with(['points', 'inspector'])
                ->forPlate($plate)
                ->newestFirst()
                ->paginate(20)
                ->withQueryString(),
        ]);
    }

    public function create(Request $request): View
    {
        return view('inspections.create', [
            'inspection' => new Inspection(['vehicle_plate' => $request->string('plate')->toString()]),
            'recorded' => [],
        ] + $this->sheet());
    }

    public function store(InspectionRequest $request): RedirectResponse
    {
        $inspection = (new Inspection)->getConnection()->transaction(function () use ($request): Inspection {
            $inspection = new Inspection($request->details());

            // The inspector is whoever is signed in. Never the request body —
            // a forged inspected_by / user_id is not even looked at.
            $inspection->inspected_by = $request->user()?->id;
            $inspection->save();

            $inspection->syncPoints($request->points());

            return $inspection;
        });

        return to_route('inspections.show', $inspection)
            ->with('status', "Inspection saved for {$inspection->vehicle_plate}.");
    }

    public function show(Inspection $inspection): View
    {
        return view('inspections.show', [
            // The linked sale is loaded id + invoice number only: an inspection
            // is a condition report, so its totals have no business being here.
            'inspection' => $inspection->load([
                'points',
                'inspector',
                'sale' => fn ($sale) => $sale->select('id', 'invoice_number'),
            ]),
        ]);
    }

    public function edit(Inspection $inspection): View
    {
        $inspection->load('points');

        return view('inspections.edit', [
            'inspection' => $inspection,
            'recorded' => $inspection->statusMap(),
        ] + $this->sheet());
    }

    public function update(InspectionRequest $request, Inspection $inspection): RedirectResponse
    {
        $inspection->getConnection()->transaction(function () use ($request, $inspection): void {
            // inspected_by is untouched: the report belongs to whoever walked
            // around the car, not to whoever last corrected a typo.
            $inspection->update($request->details());
            $inspection->syncPoints($request->points());
        });

        return to_route('inspections.show', $inspection)
            ->with('status', "Inspection updated for {$inspection->vehicle_plate}.");
    }

    /** The blank sheet every form screen renders. @return array<string, mixed> */
    private function sheet(): array
    {
        return [
            'groups' => InspectionPoint::grouped(),
            'statuses' => InspectionStatus::cases(),
        ];
    }
}
