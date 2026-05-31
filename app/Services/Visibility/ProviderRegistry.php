<?php

namespace App\Services\Visibility;

use App\Contracts\AiVisibilityProviderInterface;
use App\Services\Visibility\Providers\FakeAiVisibilityProvider;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class ProviderRegistry
{
    /**
     * @var array<string, AiVisibilityProviderInterface>
     */
    private array $providers;

    public function __construct()
    {
        $this->providers = [
            'chatgpt' => new FakeAiVisibilityProvider('chatgpt', 'ChatGPT', 'fake-gpt-visibility'),
            'claude' => new FakeAiVisibilityProvider('claude', 'Claude', 'fake-claude-visibility'),
            'gemini' => new FakeAiVisibilityProvider('gemini', 'Gemini', 'fake-gemini-visibility'),
            'perplexity' => new FakeAiVisibilityProvider('perplexity', 'Perplexity', 'fake-sonar-visibility'),
            'google_ai_overviews' => new FakeAiVisibilityProvider('google_ai_overviews', 'Google AI Overviews', 'fake-ai-overview-visibility'),
        ];
    }

    public function get(string $provider): AiVisibilityProviderInterface
    {
        $key = $this->normalize($provider);

        return $this->providers[$key] ?? throw new InvalidArgumentException("Unsupported AI visibility provider [{$provider}].");
    }

    /**
     * @return Collection<string, AiVisibilityProviderInterface>
     */
    public function providers(): Collection
    {
        return collect($this->providers);
    }

    /**
     * @return array<int, string>
     */
    public function keys(): array
    {
        return array_keys($this->providers);
    }

    public function normalize(string $provider): string
    {
        return str($provider)->lower()->replace([' ', '-'], '_')->toString();
    }
}
