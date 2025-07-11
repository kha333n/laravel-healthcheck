<?php

use App\Jobs\HealthCheckJob;
use Illuminate\Support\Facades\Schedule;

Schedule::job(HealthCheckJob::class)->everyFiveMinutes();
