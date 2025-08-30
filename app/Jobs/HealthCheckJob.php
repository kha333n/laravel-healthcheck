<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

class HealthCheckJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(): void
    {
        $routes = array_filter(array_map('trim', explode(',', config('services.health_monitor.routes', ''))));
        $timeout = (int)config('services.health_monitor.timeout', 5);
        $emails = array_filter(array_map('trim', explode(',', config('services.health_monitor.emails', ''))));
        $containers = array_filter(array_map('trim', explode(',', config('services.health_monitor.docker_containers', ''))));

        $cacheKey = getInstanceHealthKey();

        $issues = []; // concise, API-safe messages

        // HTTP route checks with retries
        foreach ($routes as $url) {
            $hostHeader = null;
            $displayUrl = null;
            $attempts = 0;
            $maxAttempts = 3;
            $success = false;

            while ($attempts < $maxAttempts && !$success) {
                try {
                    $attempts++;

                    if (str_contains($url, '|')) {
                        [$ipUrl, $hostHeader] = explode('|', $url);
                        $response = Http::timeout($timeout)
                            ->withOptions(['verify' => false])
                            ->withHeaders(['Host' => trim($hostHeader)])
                            ->get(trim($ipUrl));
                        $displayUrl = "{$hostHeader} (via {$ipUrl})";
                    } else {
                        $response = Http::timeout($timeout)->get($url);
                        $displayUrl = preg_replace('/^https?:\/\//', '', $url);
                    }

                    if ($response->successful()) {
                        $success = true;
                    } elseif ($attempts >= $maxAttempts) {
                        $issues[] = "Route failed: {$displayUrl} (HTTP {$response->status()})";
                    } else {
                        usleep(500000); // 0.5s
                    }
                } catch (\Throwable $e) {
                    $displayUrl = $displayUrl ?? preg_replace('/^https?:\/\//', '', $url);
                    if ($attempts >= $maxAttempts) {
                        $issues[] = "Route exception: {$displayUrl} ({$e->getMessage()})";
                    } else {
                        usleep(500000);
                    }
                }
            }
        }

        // Supervisor check with retries
        $supervisorAttempts = 0;
        $supervisorMax = 3;
        $supervisorHealthy = false;

        while ($supervisorAttempts < $supervisorMax && !$supervisorHealthy) {
            $supervisorAttempts++;
            $supervisorStatus = shell_exec('supervisorctl status');
            $lines = explode("\n", trim((string)$supervisorStatus));
            $errs = [];

            foreach ($lines as $line) {
                if ($line === '') continue;
                // Any line not showing RUNNING is considered an issue
                if (!preg_match('/\s+RUNNING\s+/', $line)) {
                    $errs[] = $line;
                }
            }

            if (empty($errs)) {
                $supervisorHealthy = true;
            } else {
                if ($supervisorAttempts >= $supervisorMax) {
                    foreach ($errs as $e) {
                        $issues[] = "Supervisor: {$e}";
                    }
                } else {
                    usleep(500000);
                }
            }
        }

        // Docker container checks with retries
        foreach ($containers as $container) {
            $attempts = 0;
            $success = false;

            while ($attempts < 3 && !$success) {
                $attempts++;
                $inspect = shell_exec("docker inspect --format='{{.State.Running}} {{if .State.Health}}{{.State.Health.Status}}{{end}}' " . escapeshellarg($container));

                if (!$inspect) {
                    if ($attempts >= 3) {
                        $issues[] = "Docker: {$container} not found or cannot inspect";
                    } else {
                        usleep(500000);
                    }
                    continue;
                }

                $parts = explode(' ', trim($inspect));
                $running = $parts[0] ?? 'false';
                $health = $parts[1] ?? null;

                if ($running !== 'true') {
                    if ($attempts >= 3) {
                        $issues[] = "Docker: {$container} not running";
                    } else {
                        usleep(500000);
                    }
                } elseif ($health !== null && $health !== 'healthy') {
                    if ($attempts >= 3) {
                        $issues[] = "Docker: {$container} health={$health}";
                    } else {
                        usleep(500000);
                    }
                } else {
                    $success = true;
                }
            }
        }

        // Cron check with retry
        $cronAttempts = 0;
        $cronHealthy = false;

        while ($cronAttempts < 3 && !$cronHealthy) {
            $cronAttempts++;
            $cronStatus = trim((string)shell_exec('systemctl is-active cron'));

            if ($cronStatus === 'active') {
                $cronHealthy = true;
            } else {
                if ($cronAttempts >= 3) {
                    $issues[] = "Cron: service not active";
                } else {
                    usleep(500000);
                }
            }
        }

        // Cache + email
        $ttlMinutes = 8;
        $checkedAt = now()->toIso8601String();

        if (empty($issues)) {
            Cache::put($cacheKey, [
                'status' => 'ok',
                'checked_at' => $checkedAt,
                'ttl_seconds' => $ttlMinutes * 60,
            ], now()->addMinutes($ttlMinutes));
        } else {
            Cache::put($cacheKey, [
                'status' => 'unhealthy',
                'checked_at' => $checkedAt,
                'ttl_seconds' => $ttlMinutes * 60,
                'issues' => $issues,
            ], now()->addMinutes($ttlMinutes));

            $hostName = gethostname();
            $serverName = "Server: {$hostName} Health Check";
            $subject = "[ALERT] {$serverName} - Health Check Failed";

            $htmlBody = "<p>Health check on <strong>{$serverName}</strong> failed:</p><ul>";
            foreach ($issues as $msg) {
                $htmlBody .= '<li>' . e($msg) . '</li>';
            }
            $htmlBody .= "</ul><p>Checked at: {$checkedAt}</p>";

            foreach ($emails as $to) {
                Mail::html($htmlBody, function ($msg) use ($to, $subject) {
                    $msg->to($to)->subject($subject);
                });
            }
        }
    }
}
