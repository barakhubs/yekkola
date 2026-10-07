<?php

declare(strict_types=1);

use App\Domain\Identity\Models\OtpChallenge;
use Illuminate\Support\Facades\Schedule;

// Identity (PRD-01)
Schedule::command('identity:purge-deleted-accounts')->dailyAt('02:00')->onOneServer();
Schedule::command('model:prune', ['--model' => [OtpChallenge::class]])->dailyAt('03:00')->onOneServer();
