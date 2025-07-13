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
        $routes = explode(',', config('services.health_monitor.routes', ''));
        $timeout = (int)config('services.health_monitor.timeout', 5);
        $emails = explode(',', config('services.health_monitor.emails', ''));

        $cacheKey = getInstanceHealthKey();

        $failed = [];

        foreach ($routes as $url) {
            $url = trim($url);
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
                        $failed[] = "Route check failed: <code>{$displayUrl}</code> returned status " . $response->status();
                    } else {
                        usleep(500000); // 0.5 sec delay between retries
                    }
                } catch (\Throwable $e) {
                    $displayUrl = $displayUrl ?? preg_replace('/^https?:\/\//', '', $url);
                    if ($attempts >= $maxAttempts) {
                        $failed[] = "Route check failed: <code>{$displayUrl}</code> threw an exception: " . $e->getMessage();
                    } else {
                        usleep(500000); // 0.5 sec delay between retries
                    }
                }
            }
        }

        // Supervisor check with retries
        $supervisorAttempts = 0;
        $supervisorMax = 3;
        $supervisorSuccess = false;

        while ($supervisorAttempts < $supervisorMax && !$supervisorSuccess) {
            $supervisorAttempts++;
            $supervisorStatus = shell_exec('supervisorctl status');
            $supervisorLines = explode("\n", trim($supervisorStatus));

            $supervisorErrors = [];
            foreach ($supervisorLines as $line) {
                if (empty($line)) continue;
                if (!preg_match('/\s+RUNNING\s+/', $line)) {
                    $supervisorErrors[] = "Supervisor issue: $line";
                }
            }

            if (empty($supervisorErrors)) {
                $supervisorSuccess = true;
            } else {
                if ($supervisorAttempts >= $supervisorMax) {
                    $failed = array_merge($failed, $supervisorErrors);
                } else {
                    usleep(500000);
                }
            }
        }

        $containers = explode(',', config('services.health_monitor.docker_containers', ''));
        // Docker container checks with retries
        foreach ($containers as $container) {
            $container = trim($container);
            if (empty($container)) continue;

            $attempts = 0;
            $success = false;

            while ($attempts < 3 && !$success) {
                $attempts++;
                $inspect = shell_exec("docker inspect --format='{{.State.Running}} {{if .State.Health}}{{.State.Health.Status}}{{end}}' $container");

                if (!$inspect) {
                    if ($attempts >= 3) {
                        $failed[] = "Docker container '$container' not found or cannot inspect.";
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
                        $failed[] = "Docker container '$container' is not running.";
                    } else {
                        usleep(500000);
                    }
                } elseif ($health !== null && $health !== 'healthy') {
                    if ($attempts >= 3) {
                        $failed[] = "Docker container '$container' is running but not healthy (status: $health).";
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
        $cronSuccess = false;
        while ($cronAttempts < 3 && !$cronSuccess) {
            $cronAttempts++;
            $cronStatus = trim(shell_exec('systemctl is-active cron'));

            if ($cronStatus === 'active') {
                $cronSuccess = true;
            } else {
                if ($cronAttempts >= 3) {
                    $failed[] = "Cron is not running";
                } else {
                    usleep(500000);
                }
            }
        }

        if (empty($failed)) {
            Cache::put($cacheKey, 'healthy', now()->addMinutes(8));
        } else {
            Cache::put($cacheKey, 'unhealthy', now()->addMinutes(8));

            $hostName = gethostname();
            $serverName = "Server: {$hostName} Health Check";
            $emails = array_filter($emails); // Remove empty emails

            $subject = "[ALERT] {$serverName} - Health Check Failed";
            $htmlBody = "<p>Health check on <strong>{$serverName}</strong> failed:</p><ul>";
            foreach ($failed as $msg) {
                $htmlBody .= "<li>$msg</li>";
            }
            $htmlBody .= "</ul>";

            foreach ($emails as $to) {
                Mail::html($htmlBody, function ($msg) use ($to, $subject) {
                    $msg->to(trim($to))->subject($subject);
                });
            }
        }
    }
}
