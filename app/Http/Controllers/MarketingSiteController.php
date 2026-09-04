<?php

namespace App\Http\Controllers;

use Illuminate\View\View;

class MarketingSiteController extends Controller
{
    /**
     * The public marketing site, served on the central host only.
     *
     * This is the shop window for the platform, not part of any tenant. It runs
     * outside tenancy entirely — no shop is resolved and no tenant connection is
     * opened — so it stays reachable even while every tenant database is down.
     */
    public function __invoke(): View
    {
        $whatsapp = (string) config('marketing.whatsapp');

        return view('marketing.home', [
            'email' => (string) config('marketing.email'),
            'whatsapp' => $whatsapp,
            // wa.me refuses anything but bare digits — no +, spaces or dashes.
            'whatsappDigits' => preg_replace('/\D/', '', $whatsapp),
        ]);
    }
}
