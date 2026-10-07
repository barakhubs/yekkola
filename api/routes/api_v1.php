<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

/*
| Yekkola API v1 — prefix /api/v1 (see docs/architecture.md §4.2).
| Route groups by audience: auth, public, student, professor, admin, webhooks.
| Each group is added with its feature; authorization is by policies + roles, never by client type.
*/

Route::get('/ping', fn () => ['status' => 'ok'])->name('ping');
