<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Application Rate Limiting
    |--------------------------------------------------------------------------
    |
    | Master switch for named HTTP throttles (OTP request/verify, FreeSWITCH
    | XML-CURL, etc). Set RATE_LIMIT_ENABLED=false to hard-disable limits.
    | Runtime temporary disable is controlled by `php artisan rate-limit`.
    |
    */

    'enabled' => env('RATE_LIMIT_ENABLED', true),

];
