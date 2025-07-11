# 🩺 Laravel Health Check App

This lightweight Laravel application performs **automated system health checks** and **reports failures via email**. It
is designed to run on servers (e.g., VPS or container) and integrates with Laravel scheduler to periodically monitor:

- Routes (URLs)
- Supervisor services
- Docker containers
- Cron service

Cached health status is exposed via an endpoint for use with **NLBs or uptime tools**.

---

## 🚀 Features

- ✅ URL response validation (status code check)
- ✅ Supervisor process status check
- ✅ Docker container running & health check
- ✅ Cron service active check
- ✅ Failsafe cache storage of health result
- ✅ Email alerts for failures
- ✅ Designed for lightweight headless deployment

---

## ⚙️ Setup Instructions

### 1. Clone the Repository

```bash
git clone https://github.com/kha333n/laravel-healthcheck.git
cd laravel-healthcheck
```

### 2. Install Dependencies

```bash
composer install
```

### 3. Configure Environment

Copy `.env.example` and edit required values:

```bash
cp .env.example .env
php artisan key:generate
```

### 4. Update Config

Add your health monitor settings in `.env`:

```
HEALTH_ROUTES=https://your-app.com/health,http://127.0.0.1|https://api.your-app.com/ping
HEALTH_TIMEOUT=5
HEALTH_ALERT_EMAILS=admin@example.com,devops@example.com
HEALTH_DOCKER_CONTAINERS=nginx,laravel_app,redis
HEALTH_SERVER_NAME=My Laravel Server 1
```

Note:

- `HEALTH_ROUTES` is a comma-separated list of URLs to check.
- If a simple URL is given, it directly hits the URL.
- If a URL is followed by `|`, it will be checked on localhost instead by setting `Host` header to ensure it works on
  the same server.
- If you wan to check HTTP or HTTPS add with local IP only domain must be without protocol, e.g.
  `https://127.0.0.1|your-app.com` OR if app is on different port then you can set it like
  `https://127.0.0.1:8000|your-app.com`.  ***"Adding protocol in host will not work check will fail!"***
- Local IP checks are useful for internal APIs or services that may not be accessible externally, OR if you want to
  avoid
  external DNS resolution and check behind a Load Balancer.
- Local IP checks will be done ignoring SSL verification, As this might not have a valid SSL internally. Common example:
  Cloudflare Origin CA
  certificates.

Then add cron entry:

```bash
* * * * * cd /path/to/laravel-healthcheck && php artisan schedule:run >> /dev/null 2>&1
```

---

## 📬 Email Alert Example

If a health check fails, admins receive an HTML email like:

```
Subject: [ALERT] My Laravel Server 1 - Health Check Failed

• Route check failed: <code>your-app.com/ping</code> returned status 500
• Docker container 'laravel_app' is not running
• Cron is not running
```

---

## 🧪 Test Manually

To run health check manually:

```bash
php artisan queue:work --once
php artisan schedule:run
```

Or dispatch directly:

```bash
php artisan tinker
>>> dispatch(new \App\Jobs\HealthCheckJob());
```

---

## 🧼 Supervisor Test Tips

To simulate failures:

- Create a dummy `.conf` file in `/etc/supervisor/conf.d/` that starts a fake service and does not run.
- Restart supervisor and observe the health check catch it.

---

## 🐳 Docker Notes

If a container does **not** define a health check, it's still considered healthy if running.
Only when `.State.Health.Status` exists, it will be checked.

---

## 📁 File Structure Overview

```
app/
└── Jobs/
    └── HealthCheckJob.php        # Main job logic

routes/
└── api.php                       # Health status route

.env.example                      # Sample env file
```

---

## 📜 License

I don't care about licenses, use it as you wish. Just don't be a jerk.
