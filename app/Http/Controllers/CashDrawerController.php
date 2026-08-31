<?php

namespace App\Http\Controllers;

use App\Support\CashDrawer;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CashDrawerController extends Controller
{
    public function index(Request $request): View
    {
        return view('cash-drawer.index', CashDrawer::forRequest($request)->toViewData());
    }
}
