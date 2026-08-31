<?php

namespace App\Http\Controllers;

use App\Enums\Permission;
use App\Support\ServiceHistory;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ServiceHistoryController extends Controller
{
    /**
     * "Lookup previous vehicle service history by phone number" (PRD §1).
     *
     * Pricing visibility is decided here, not in the Blade: a user without
     * pricing.view never has the money columns fetched in the first place.
     */
    public function index(Request $request): View
    {
        // ?q[]=… hands us an array. Casting that to a string warns and then
        // looks up the literal "Array", so anything but a string is no search.
        $search = trim(is_string($q = $request->query('q')) ? $q : '');
        $withPricing = (bool) $request->user()?->can(Permission::ViewPricing->value);

        return view('service-history.index', [
            'search' => $search,
            'searched' => $search !== '',
            'showPricing' => $withPricing,
            'visits' => ServiceHistory::lookup($search, $withPricing),
        ]);
    }
}
