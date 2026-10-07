<?php

declare(strict_types=1);

// Titles for API error codes (docs/architecture.md §4.1). Nested keys mirror the dotted code.
return [
    'generic' => 'Something went wrong.',
    'validation' => [
        'failed' => 'Some of the information is invalid.',
    ],
    'auth' => [
        'unauthenticated' => 'Please sign in to continue.',
        'forbidden' => 'You are not allowed to do this.',
    ],
    'resource' => [
        'not_found' => 'Not found.',
    ],
    'http' => [
        'method_not_allowed' => 'Method not allowed.',
        'error' => 'The request could not be processed.',
    ],
    'rate_limited' => 'Too many attempts. Please try again later.',
    'server' => [
        'error' => 'Server error. Please try again.',
    ],
    'idempotency' => [
        'key_missing' => 'The Idempotency-Key header is required for this request.',
        'key_reused' => 'This idempotency key was already used for a different request.',
        'in_progress' => 'An identical request is already being processed.',
    ],
    'money' => [
        'currency_mismatch' => 'The amounts are not in the same currency.',
    ],
    'phone' => [
        'invalid' => 'Invalid phone number.',
    ],
];
