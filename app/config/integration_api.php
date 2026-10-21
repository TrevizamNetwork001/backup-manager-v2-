<?php

return [
    'enabled' => (bool) env('INTEGRATION_API_ENABLED', false),
    'allowed_ips' => array_values(array_filter(array_map('trim', explode(',', (string) env('INTEGRATION_API_ALLOWED_IPS', ''))))),
];
