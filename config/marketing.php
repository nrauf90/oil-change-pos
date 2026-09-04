<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Public marketing site
    |--------------------------------------------------------------------------
    |
    | Contact details shown on the public site served at the central host root.
    | Kept in config rather than in the Blade view so that changing a phone
    | number is a deploy variable, not a code edit.
    |
    */

    'email' => env('MARKETING_EMAIL', 'hello@mnr-itsolutions.com'),

    // Displayed as written. The wa.me link strips it down to digits.
    'whatsapp' => env('MARKETING_WHATSAPP', '+92 333 5527619'),

];
