<?php

namespace App\Enums;

enum ResearchFindingType: string
{
    case INSIGHT = 'insight';
    case STATISTIC = 'statistic';
    case QUOTE = 'quote';
    case ENTITY = 'entity';
    case QUESTION = 'question';
}
