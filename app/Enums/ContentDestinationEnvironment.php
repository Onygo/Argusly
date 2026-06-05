<?php

namespace App\Enums;

enum ContentDestinationEnvironment: string
{
    case PRODUCTION = 'production';
    case STAGING = 'staging';
    case DEVELOPMENT = 'development';
}
