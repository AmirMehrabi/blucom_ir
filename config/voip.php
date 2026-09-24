<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Country Code
    |--------------------------------------------------------------------------
    |
    | Used when normalizing national numbers to the canonical E.164 form.
    |
    */

    'country_code' => env('VOIP_COUNTRY_CODE', '98'),

    'allowed_outbound_gateways' => ['provider-trunk'],
    'provider_trunk_host' => env('VOIP_PROVIDER_TRUNK_HOST', '172.28.238.162'),

    /*
    |--------------------------------------------------------------------------
    | FreeSWITCH XML-CURL
    |--------------------------------------------------------------------------
    |
    | Shared secret protecting the internal server-to-server endpoint that
    | FreeSWITCH uses to fetch directory and dialplan configuration.
    |
    */

    'xml_curl' => [
        'token' => env('FREESWITCH_XML_CURL_TOKEN'),
    ],

    /*
    |--------------------------------------------------------------------------
    | SIP Endpoint Hints
    |--------------------------------------------------------------------------
    |
    | Shown once when delivering new extension credentials to a customer.
    |
    */

    'sip_host' => env('VOIP_SIP_HOST', '5.202.19.86'),
    'sip_port' => (int) env('VOIP_SIP_PORT', 5060),

    /*
    |--------------------------------------------------------------------------
    | FreeSWITCH Directory Domain
    |--------------------------------------------------------------------------
    |
    | Domain name used in XML-CURL directory responses. Should match the
    | domain Sofia expects for the internal profile.
    |
    */

    'directory_domain' => env('VOIP_DIRECTORY_DOMAIN', '5.202.19.86'),

];
