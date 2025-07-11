<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Cache;

class HealthStatusController extends Controller
{
    public function __invoke()
    {
        if (Cache::get('system_health_status') === 'healthy') {
            return response()->json(['status' => 'ok'], 200);
        }

        return response()->json(['status' => 'unhealthy'], 503);
    }
}
