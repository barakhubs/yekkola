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
    'request' => [
        'busy' => 'Another operation is in progress. Try again in a moment.',
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
    'professor' => [
        'suspended' => 'Your professor account is suspended.',
        'already_professor' => 'You are already a professor on Yekkola.',
        'slug_already_changed' => 'Your page address can only be changed once.',
        'required' => 'This area is for approved professors.',
    ],
    'application' => [
        'not_found' => 'No application found.',
        'locked' => 'This application can no longer be edited.',
        'incomplete' => 'Fill in all required fields before submitting your application.',
        'reapply_cooldown' => 'You can submit a new application later.',
        'invalid_transition' => 'This action is not possible for an application in this state.',
        'own_application' => 'You cannot review your own file.',
        'too_many_credentials' => 'Maximum number of files reached.',
    ],
    'terms' => [
        'not_accepted' => 'You must accept the professor agreement.',
        'acceptance_required' => 'The professor agreement has changed. Accept the new version to continue.',
    ],
    'kyc' => [
        'already_pending' => 'An identity check is already in progress.',
        'already_verified' => 'Your identity is already verified.',
        'back_required' => 'Add the back of your ID document.',
        'not_found' => 'No identity check found.',
        'not_pending' => 'This check has already been handled.',
    ],
    'course' => [
        'draft_not_editable' => 'This draft is in review and cannot be edited.',
        'no_draft' => 'This course has no draft in progress.',
        'draft_exists' => 'A draft already exists for this course.',
        'not_published' => 'This course has never been published.',
        'invalid_transition' => 'This action is not possible for this course right now.',
        'not_ready_for_review' => 'Complete the course before submitting it for review.',
        'free_limit_reached' => 'You have reached the maximum number of free courses.',
        'free_hours_limit_reached' => 'This course would exceed the limit of :limit hours of free content.',
        'cannot_delete_published' => 'A published course cannot be deleted; archive it instead.',
        'own_course' => 'You cannot review your own course.',
        'invalid_order' => 'The order sent does not match the course content.',
    ],
    'lesson' => [
        'preview_limit_reached' => 'Maximum number of preview lessons reached.',
        'type_mismatch' => 'This content does not match the lesson type.',
    ],
    'phone' => [
        'invalid' => 'Invalid phone number.',
        'taken' => 'This number is already used by another account.',
        'unchanged' => 'This is already your current number.',
        'country_not_supported' => 'Numbers from this country are not supported yet.',
    ],
];
