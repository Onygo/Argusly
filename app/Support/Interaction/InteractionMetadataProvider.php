<?php

namespace App\Support\Interaction;

interface InteractionMetadataProvider
{
    /**
     * @return array<int, string>
     */
    public function resourceTypes(): array;

    /**
     * @return array<int, string>
     */
    public function actionKeys(): array;

    public function registerTypes(ResourceRegistry $resources): ResourceRegistry;

    public function registerActions(ActionRegistry $actions): ActionRegistry;

    public function resourceFor(object $subject): ?Resource;
}
