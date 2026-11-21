<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Tax Rate
    |--------------------------------------------------------------------------
    |
    | The tax rate applied to sales, expressed as a decimal (e.g. 0.08 for 8%).
    | Internally converted to basis points (800 for 8%) for integer math.
    |
    */

    'tax_rate' => (int) round(((float) env('POS_TAX_RATE', 0.08)) * 10000),

];
