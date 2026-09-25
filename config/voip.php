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

    // Leave Sofia gateway provisioning off until the live profile and XML-CURL
    // binding have been inspected and the existing trunk has been backed up.
    'gateway_xml_enabled' => (bool) env('VOIP_GATEWAY_XML_ENABLED', false),
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

    // Matches the working FreeSWITCH directory/default.xml domain dial-string.
    // FreeSWITCH uses this when bridging calls to a registered directory user.
    'directory_dial_string' => '{^^:sip_invite_domain=${dialed_domain}:presence_id=${dialed_user}@${dialed_domain}}${sofia_contact(*/${dialed_user}@${dialed_domain})},${verto_contact(${dialed_user}@${dialed_domain})}',

];
