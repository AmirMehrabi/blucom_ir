<?php

return [

    'recordings' => [
        // Enable only after the recording module, completion script and shared
        // storage permissions have been verified on the FreeSWITCH host.
        'enabled' => (bool) env('VOIP_RECORDINGS_ENABLED', false),
        'spool' => env('VOIP_RECORDINGS_SPOOL', storage_path('app/recording-spool')),
        'quota_mb' => 1024,
        'max_quota_mb' => (int) env('VOIP_RECORDINGS_MAX_QUOTA_MB', 10240),
        'min_free_mb' => (int) env('VOIP_RECORDINGS_MIN_FREE_MB', 512),
        'completion_grace_seconds' => 600,
    ],

    'live' => [
        'enabled' => (bool) env('VOIP_LIVE_ENABLED', false),
        'host' => env('FREESWITCH_ESL_HOST', '127.0.0.1'),
        'port' => (int) env('FREESWITCH_ESL_PORT', 8021),
        'password' => env('FREESWITCH_ESL_PASSWORD'),
        'profile' => env('FREESWITCH_INTERNAL_PROFILE', 'internal'),
        'cache_store' => env('VOIP_LIVE_CACHE_STORE', 'redis'),
        'stale_after' => 20,
    ],

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
    'queues_enabled' => (bool) env('VOIP_QUEUES_ENABLED', false),
    'provider_trunk_host' => env('VOIP_PROVIDER_TRUNK_HOST', '172.28.238.162'),
    'cdr_csv_path' => env('VOIP_CDR_CSV_PATH', '/var/log/freeswitch/cdr-csv/Master.csv'),
    'cdr_timezone' => env('VOIP_CDR_TIMEZONE', 'UTC'),
    'display_timezone' => env('VOIP_DISPLAY_TIMEZONE', 'Asia/Tehran'),

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
