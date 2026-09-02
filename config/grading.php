<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Pass thresholds by academic level
    |--------------------------------------------------------------------------
    |
    | SHS uses DepEd-style 0–100 scale (pass >= threshold).
    | College uses CHED-style 1.00–5.00 scale (pass <= threshold).
    |
    */
    'shs_pass_minimum' => 75,
    'college_pass_maximum' => 3.00,
];
