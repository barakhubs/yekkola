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
        'csrf_mismatch' => 'Your session expired. Please try again.',
        'reauthentication_required' => 'For your security, sign in again and retry.',
        'session_expired' => 'You were signed out. Please sign in again.',
        'client_unknown' => 'Unknown client: send device details or use the website.',
    ],
    'resource' => [
        'not_found' => 'Not found.',
    ],
    'http' => [
        'bad_request' => 'Bad request.',
        'method_not_allowed' => 'Method not allowed.',
        'payload_too_large' => 'The request is too large.',
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
    'webhook' => [
        'invalid_signature' => 'Invalid webhook signature.',
        'invalid_payload' => 'Invalid webhook payload.',
    ],
    'otp' => [
        'cooldown' => 'Please wait before requesting a new code.',
        'rate_limited' => 'Too many codes requested. Try again later.',
        'expired' => 'This code has expired. Request a new one.',
        'invalid' => 'Incorrect code.',
        'too_many_attempts' => 'Too many attempts. Request a new code.',
        'locked' => 'Too many incorrect codes for this number. Try again later.',
        'temporarily_unavailable' => 'Sending codes is temporarily unavailable. Try again later.',
    ],
    'account' => [
        'banned' => 'This account has been disabled.',
        'suspended' => 'This account is suspended. Contact support.',
    ],
    'device' => [
        'limit_reached' => 'Device limit reached. Remove a device to continue.',
        'not_found' => 'Device not found.',
    ],
    'bot_challenge' => [
        'failed' => 'Security check failed. Please try again.',
    ],
    'export' => [
        'not_ready' => 'No export is available yet.',
        'in_progress' => 'An export is already being prepared.',
        'not_found' => 'No export requested yet.',
        'rate_limited' => 'You can request one export per day.',
        'filename' => 'yekkola-my-data.json',
    ],
    'phone' => [
        'invalid' => 'Invalid phone number.',
        'taken' => 'This number is already used by another account.',
        'unchanged' => 'This is already your current number.',
        'country_not_supported' => 'Numbers from this country are not supported yet.',
    ],
];
