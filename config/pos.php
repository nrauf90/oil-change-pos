<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Stale draft threshold
    |--------------------------------------------------------------------------
    |
    | How long a draft bill may stay open before the counter list flags it.
    | Nothing is ever deleted automatically — a bill open since yesterday is
    | usually a car still on the ramp, not a mistake. The flag is a prompt to
    | look, not an instruction to act.
    |
    */

    'stale_draft_hours' => (int) env('POS_STALE_DRAFT_HOURS', 24),

];
