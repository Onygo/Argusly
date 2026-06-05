<?php

namespace App\Agents\Support;

enum AgentTriggerType: string
{
    case MANUAL = 'manual';
    case EVENT = 'event';
    case SCHEDULED = 'scheduled';
    case API = 'api';
    case DEBUG = 'debug';
}
