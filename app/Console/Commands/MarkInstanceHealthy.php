<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class MarkInstanceHealthy extends Command
{
    protected $signature = 'health:set-healthy';
    protected $description = 'Set this instance as healthy immediately on boot';

    public function handle(): int
    {
        Cache::put(getInstanceHealthKey(), 'healthy', now()->addMinutes(8));
        return 0;
    }
}
