<?php

namespace App\Services\BrandGrowthPlanning\Analyzers;

use App\Services\BrandGrowthPlanning\BrandGrowthAnalyzerResult;

interface BrandGrowthAnalyzer
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function analyze(array $context): BrandGrowthAnalyzerResult;
}
