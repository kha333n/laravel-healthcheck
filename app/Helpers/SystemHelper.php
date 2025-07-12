<?php

function getInstanceHealthKey(): string
{
    foreach (['eth0', 'ens5'] as $iface) {
        $mac = @file_get_contents("/sys/class/net/{$iface}/address");
        if ($mac) return 'system_health_status:' . trim($mac);
    }
    return 'system_health_status:unknown';
}
