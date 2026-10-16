<?php

declare(strict_types=1);

use App\Domain\Identity\Models\DataExport;
use App\Domain\Identity\Models\OtpChallenge;
use Illuminate\Support\Facades\Schedule;

// Identity (PRD-01)
Schedule::command('identity:purge-deleted-accounts')->dailyAt('02:00')->onOneServer();
Schedule::command('professors:expire-stale-applications')->dailyAt('02:30')->onOneServer();
Schedule::command('model:prune', ['--model' => [OtpChallenge::class, DataExport::class]])->dailyAt('03:00')->onOneServer();

// Commerce (PRD-05 FR-10)
Schedule::command('commerce:reconcile')->everyFiveMinutes()->withoutOverlapping(10)->onOneServer();
