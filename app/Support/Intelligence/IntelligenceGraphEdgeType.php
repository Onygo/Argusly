<?php

namespace App\Support\Intelligence;

enum IntelligenceGraphEdgeType: string
{
    case RELATES_TO = 'relates_to';
    case REFERENCES = 'references';
    case MENTIONS = 'mentions';
    case OBSERVES = 'observes';
    case EVIDENCES = 'evidences';
    case SUPPORTS = 'supports';
    case DRIVES = 'drives';
    case RECOMMENDS = 'recommends';
    case REPORTS = 'reports';
    case BRIEFS = 'briefs';
    case MEASURES = 'measures';
    case DERIVES_FROM = 'derives_from';
    case INFORMS = 'informs';
    case ACTS_ON = 'acts_on';
    case ACHIEVES = 'achieves';
}
