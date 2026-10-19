<?php

return [
    'trusted_ca_bundle' => env('SIGNATURE_TRUSTED_CA_BUNDLE'),
    'require_holder_match' => (bool) env('SIGNATURE_REQUIRE_HOLDER_MATCH', true),
];
