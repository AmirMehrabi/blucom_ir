<?php

return [
    'public_domain' => env('PUBLIC_DOMAIN', 'blucom.ir'),
    'admin_domain' => env('ADMIN_PORTAL_DOMAIN', 'admin.blucom.ir'),
    'customer_domain' => env('CUSTOMER_PORTAL_DOMAIN', 'my.blucom.ir'),
    'phone_domain' => env('PHONE_PORTAL_DOMAIN', 'phone.blucom.ir'),
    'customer_session_cookie' => env('CUSTOMER_SESSION_COOKIE', 'blucom_customer_session'),
    'phone_session_cookie' => env('PHONE_SESSION_COOKIE', 'blucom_phone_session'),
];
