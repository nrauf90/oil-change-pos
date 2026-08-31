<?php

namespace App\Http\Controllers;

use App\Support\CounterScripts;
use Illuminate\View\View;

class ScriptController extends Controller
{
    /**
     * The standalone reading / training page for the counter scripts.
     *
     * The same content is available without leaving a half-typed bill via the
     * <x-counter-scripts /> drawer on the sale screen.
     */
    public function index(): View
    {
        return view('scripts.index', [
            'groups' => CounterScripts::all(),
        ]);
    }
}
