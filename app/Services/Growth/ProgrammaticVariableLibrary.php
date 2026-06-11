<?php

namespace App\Services\Growth;

use App\Enums\ProgrammaticPatternType;

class ProgrammaticVariableLibrary
{
    /**
     * @return array<int,string>
     */
    public function variablesFor(ProgrammaticPatternType $pattern): array
    {
        return match ($pattern) {
            ProgrammaticPatternType::INDUSTRY_PAGE => ['SaaS', 'healthcare', 'finance', 'manufacturing', 'ecommerce', 'education', 'legal', 'real estate'],
            ProgrammaticPatternType::LOCATION_PAGE => ['Amsterdam', 'Rotterdam', 'Utrecht', 'Eindhoven', 'Den Haag', 'Groningen', 'Breda', 'Haarlem'],
            ProgrammaticPatternType::USE_CASE_PAGE => ['marketing teams', 'support teams', 'sales teams', 'content operations', 'compliance workflows', 'agency operations'],
            ProgrammaticPatternType::INTEGRATION_PAGE => ['HubSpot', 'Salesforce', 'WordPress', 'Slack', 'Notion', 'Zapier', 'Shopify', 'Google Analytics'],
            ProgrammaticPatternType::FEATURE_PAGE => ['reporting', 'automation', 'governance', 'workflow approvals', 'AI visibility tracking', 'content briefs'],
            ProgrammaticPatternType::COMPARISON_PAGE => ['Contentful', 'Webflow', 'WordPress', 'Jasper', 'Surfer SEO', 'Semrush'],
            ProgrammaticPatternType::ALTERNATIVE_PAGE => ['Contentful', 'Webflow', 'WordPress', 'Jasper', 'Surfer SEO', 'Semrush'],
            ProgrammaticPatternType::FAQ_LIBRARY => ['wat is het', 'hoe werkt het', 'wat kost het', 'wanneer gebruik je het', 'wat zijn de voordelen', 'hoe meet je resultaat'],
            ProgrammaticPatternType::AI_ANSWER_LIBRARY => ['wat is de beste aanpak', 'hoe vergelijk je opties', 'welke criteria tellen', 'wat zijn risico’s', 'hoe start je ermee'],
        };
    }
}
