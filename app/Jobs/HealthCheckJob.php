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

        $failed = [];

        foreach ($routes as $url) {
            try {
                $response = Http::timeout($timeout)->get(trim($url));
                if (!$response->successful()) {
                    $cleanUrl = preg_replace('/^https?:\/\//', '', $url);
                    $failed[] = "Route check failed: <code>{$cleanUrl}</code> returned status " . $response->status();
                }
            } catch (\Throwable $e) {
                $cleanUrl = preg_replace('/^https?:\/\//', '', $url);
                $failed[] = "Route check failed: <code>{$cleanUrl}</code> threw an exception: " . $e->getMessage();
            }
        }

        $supervisorStatus = shell_exec('supervisorctl status');
        $supervisorLines = explode("\n", trim($supervisorStatus));

        foreach ($supervisorLines as $line) {
            if (empty($line)) continue;

            // A line is OK only if it contains 'RUNNING' after the service name
            if (!preg_match('/\s+RUNNING\s+/', $line)) {
                $failed[] = "Supervisor issue: $line";
            }
        }

        $containers = explode(',', config('services.health_monitor.docker_containers', ''));

        foreach ($containers as $container) {
            $container = trim($container);
            if (empty($container)) continue;

            // Check container running status
            $inspect = shell_exec("docker inspect --format='{{.State.Running}} {{if .State.Health}}{{.State.Health.Status}}{{end}}' $container");

            if (!$inspect) {
                $failed[] = "Docker container '$container' not found or cannot inspect.";
                continue;
            }

            $parts = explode(' ', trim($inspect));
            $running = $parts[0] ?? 'false';
            $health = $parts[1] ?? null;

            if ($running !== 'true') {
                $failed[] = "Docker container '$container' is not running.";
            } elseif ($health !== null && $health !== 'healthy') {
                $failed[] = "Docker container '$container' is running but not healthy (status: $health).";
            }
        }

        $cronStatus = shell_exec('systemctl is-active cron');
        if (trim($cronStatus) !== 'active') {
            $failed[] = "Cron is not running";
        }

        if (empty($failed)) {
            Cache::put('system_health_status', 'healthy', now()->addMinutes(6));
        } else {
            Cache::put('system_health_status', 'unhealthy', now()->addMinutes(6));

            $serverName = config('services.health_monitor.server_name', 'Server');
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
