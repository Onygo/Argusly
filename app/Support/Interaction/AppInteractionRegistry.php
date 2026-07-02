<?php

namespace App\Support\Interaction;

use App\Support\Interaction\Providers\AppContentInteractionProvider;
use App\Support\Interaction\Providers\AppResearchInteractionProvider;
use App\Support\Interaction\Providers\AppSignalInteractionProvider;
use App\Support\Interaction\Providers\AppSiteInteractionProvider;

final class AppInteractionRegistry
{
    /**
     * @return array<int, InteractionMetadataProvider>
     */
    public static function providers(): array
    {
        return [
            new AppContentInteractionProvider(),
            new AppResearchInteractionProvider(),
            new AppSiteInteractionProvider(),
            new AppSignalInteractionProvider(),
        ];
    }

    public static function actionRegistry(): ActionRegistry
    {
        $registry = ActionRegistry::make();

        foreach (self::providers() as $provider) {
            $provider->registerActions($registry);
        }

        return $registry;
    }

    /**
     * @param iterable<int, object> $subjects
     */
    public static function resourceRegistryFor(iterable $subjects = []): ResourceRegistry
    {
        $registry = ResourceRegistry::make();
        $providers = self::providers();

        foreach ($providers as $provider) {
            $provider->registerTypes($registry);
        }

        foreach ($subjects as $subject) {
            foreach ($providers as $provider) {
                $resource = $provider->resourceFor($subject);

                if ($resource !== null) {
                    $registry->register($resource);
                    break;
                }
            }
        }

        return $registry;
    }
}
