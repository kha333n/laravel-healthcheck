<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Cache;

class HealthStatusController extends Controller
{
    public function __invoke()
    {
        $val = Cache::get(getInstanceHealthKey());

        // Backward compatibility: if older jobs stored a string.
        if (is_string($val)) {
            if ($val === 'healthy') {
                return response()->json(['status' => 'ok'], 200);
            }
            return response()->json(['status' => 'unhealthy'], 503);
        }

        // New structured payload
        if (is_array($val)) {
            $status = $val['status'] ?? 'unhealthy';
            if ($status === 'ok') {
                return response()->json([
                    'status' => 'ok',
                    'checked_at' => $val['checked_at'] ?? null,
                ], 200);
            }

            return response()->json([
                'status' => 'unhealthy',
                'checked_at' => $val['checked_at'] ?? null,
                'issues' => $val['issues'] ?? [],
            ], 503);
        }

        // If nothing cached, consider unknown/unhealthy.
        return response()->json(['status' => 'unhealthy', 'issues' => ['No recent health data']], 503);
    }
}
