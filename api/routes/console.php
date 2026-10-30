<?php

declare(strict_types=1);

use App\Domain\Identity\Models\DataExport;
use App\Domain\Identity\Models\OtpChallenge;
use App\Domain\Protection\Models\StreamSession;
use Illuminate\Support\Facades\Schedule;

// Identity (PRD-01)
Schedule::command('identity:purge-deleted-accounts')->dailyAt('02:00')->onOneServer();
Schedule::command('professors:expire-stale-applications')->dailyAt('02:30')->onOneServer();
Schedule::command('model:prune', ['--model' => [OtpChallenge::class, DataExport::class, StreamSession::class]])->dailyAt('03:00')->onOneServer();

// Commerce (PRD-05 FR-10)
Schedule::command('commerce:reconcile')->everyFiveMinutes()->withoutOverlapping(10)->onOneServer();

// Finance (PRD-08 FR-03, FR-06, FR-08)
Schedule::command('finance:release-earnings')->hourly()->withoutOverlapping(60)->onOneServer();
Schedule::command('finance:build-payout-batches')->dailyAt('04:00')->onOneServer();
Schedule::command('finance:sync-payouts')->everyFiveMinutes()->withoutOverlapping(10)->onOneServer();
