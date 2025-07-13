<?php

function getInstanceHealthKey(): string
{
    return 'system_health_status:' . gethostname();
}

