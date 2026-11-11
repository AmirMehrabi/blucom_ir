<?php

return [
    'enabled' => (bool) env('VOIP_AUDIO_CALLS_ENABLED', false),
    'all_lines' => env('VOIP_AUDIO_CALL_LINE_IDS') === '*',
    'line_ids' => array_values(array_filter(array_map('intval', explode(',', (string) env('VOIP_AUDIO_CALL_LINE_IDS', ''))))),
];
