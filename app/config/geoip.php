<?php

return [
    'country_database' => env('GEOIP_COUNTRY_DB', '/data/geoip/GeoLite2-Country.mmdb'),
    'asn_database' => env('GEOIP_ASN_DB', '/data/geoip/GeoLite2-ASN.mmdb'),
];
