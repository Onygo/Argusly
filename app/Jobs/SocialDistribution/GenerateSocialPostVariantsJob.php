<?php

namespace App\Jobs\SocialDistribution;

use App\Enums\SocialPostVariantStatus;
use App\Models\SocialPostVariant;
use App\Services\SocialDistribution\SocialDistributionAuditLogger;
use App\Services\SocialDistribution\SocialPostVariantGenerationProvider;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class GenerateSocialPostVariantsJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 2;

    public int $timeout = 180;

    public int $uniqueFor = 300;

    /**
     * @param array<int, string> $variantIds
     */
    public function __construct(
        public readonly array $variantIds,
    ) {
        $this->onQueue((string) config('agentic_marketing.queue', 'default'));
    }

    public function uniqueId(): string
    {
        return 'social-variant-generation:'.sha1(implode('|', $this->variantIds));
    }

    public function handle(SocialDistributionAuditLogger $audit, SocialPostVariantGenerationProvider $provider): void
    {
        SocialPostVariant::query()
            ->with(['campaign', 'campaignContent', 'workspace'])
            ->whereIn('id', $this->variantIds)
            ->whereIn('status', [
                SocialPostVariantStatus::GENERATION_REQUESTED->value,
                SocialPostVariantStatus::FAILED->value,
            ])
            ->each(function (SocialPostVariant $variant) use ($audit, $provider): void {
                $before = $variant->attributesToArray();

                $variant->forceFill([
                    'status' => SocialPostVariantStatus::GENERATING->value,
                ])->save();

                $audit->record($variant, 'variant.generation_started', $before, $variant->attributesToArray());

                try {
                    $generated = $provider->generate($variant);

                    $variant->forceFill([
                        'status' => SocialPostVariantStatus::DRAFT->value,
                        'hook' => $generated['hook'],
                        'body' => $generated['body'],
                        'hashtags' => $generated['hashtags'],
                        'mentions' => $generated['mentions'],
                        'quality_score' => $generated['quality_score'],
                        'generation_model' => $generated['generation_model'],
                        'generation_result' => $generated['generation_result'],
                        'generated_at' => now(),
                    ])->save();

                    $audit->record($variant, 'variant.generated', $before, $variant->attributesToArray());
                } catch (Throwable $exception) {
                    $message = $exception instanceof \App\Services\Llm\Exceptions\LlmException
                        ? ($exception->userMessage ?: $exception->getMessage())
                        : $exception->getMessage();

                    $variant->forceFill([
                        'status' => SocialPostVariantStatus::FAILED->value,
                        'generation_result' => [
                            'error_code' => $exception instanceof \App\Services\Llm\Exceptions\LlmException
                                ? 'AI_GENERATION_PROVIDER_NOT_CONFIGURED'
                                : 'AI_GENERATION_FAILED',
                            'message' => $message ?: 'Configure a generation provider before producing copy.',
                            'exception' => $exception::class,
                        ],
                    ])->save();

                    $audit->record($variant, 'variant.generation_failed', $before, $variant->attributesToArray(), [
                        'reason' => 'provider_not_configured',
                    ]);
                }
            });
    }

    public function failed(Throwable $exception): void
    {
        SocialPostVariant::query()
            ->whereIn('id', $this->variantIds)
            ->update([
                'status' => SocialPostVariantStatus::FAILED->value,
                'generation_result' => [
                    'error_code' => 'GENERATION_JOB_FAILED',
                    'message' => $exception->getMessage(),
                ],
            ]);
    }
}
