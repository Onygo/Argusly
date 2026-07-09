<?php

namespace App\Support\Intelligence;

use InvalidArgumentException;

enum ReasoningStage: string
{
    case OBSERVATION = 'observation';
    case SIGNAL = 'signal';
    case INSIGHT = 'insight';
    case RECOMMENDATION = 'recommendation';
    case ACTION = 'action';
    case OUTCOME = 'outcome';

    /**
     * @return array<int, self>
     */
    public static function ordered(): array
    {
        return [
            self::OBSERVATION,
            self::SIGNAL,
            self::INSIGHT,
            self::RECOMMENDATION,
            self::ACTION,
            self::OUTCOME,
        ];
    }

    public static function fromIntelligenceStage(IntelligenceStage $stage): self
    {
        return match ($stage) {
            IntelligenceStage::RAW_OBSERVATION => self::OBSERVATION,
            IntelligenceStage::SIGNAL => self::SIGNAL,
            IntelligenceStage::INSIGHT => self::INSIGHT,
            IntelligenceStage::RECOMMENDATION => self::RECOMMENDATION,
            IntelligenceStage::ACTION => self::ACTION,
            IntelligenceStage::OUTCOME => self::OUTCOME,
        };
    }

    public static function normalize(self|IntelligenceStage|string $stage): self
    {
        if ($stage instanceof self) {
            return $stage;
        }

        if ($stage instanceof IntelligenceStage) {
            return self::fromIntelligenceStage($stage);
        }

        $normalized = str($stage)->lower()->trim()->slug('_')->toString();

        if ($normalized === IntelligenceStage::RAW_OBSERVATION->value) {
            return self::OBSERVATION;
        }

        foreach (self::cases() as $case) {
            if ($case->value === $normalized) {
                return $case;
            }
        }

        throw new InvalidArgumentException(sprintf('Unknown reasoning stage [%s].', $stage));
    }

    public function intelligenceStage(): IntelligenceStage
    {
        return match ($this) {
            self::OBSERVATION => IntelligenceStage::RAW_OBSERVATION,
            self::SIGNAL => IntelligenceStage::SIGNAL,
            self::INSIGHT => IntelligenceStage::INSIGHT,
            self::RECOMMENDATION => IntelligenceStage::RECOMMENDATION,
            self::ACTION => IntelligenceStage::ACTION,
            self::OUTCOME => IntelligenceStage::OUTCOME,
        };
    }

    public function graphReferenceType(): string
    {
        return match ($this) {
            self::OBSERVATION => IntelligenceGraphReference::TYPE_OBSERVATION,
            self::SIGNAL => IntelligenceGraphReference::TYPE_SIGNAL,
            self::INSIGHT => IntelligenceGraphReference::TYPE_INSIGHT,
            self::RECOMMENDATION => IntelligenceGraphReference::TYPE_RECOMMENDATION,
            self::ACTION => IntelligenceGraphReference::TYPE_ACTION,
            self::OUTCOME => IntelligenceGraphReference::TYPE_OUTCOME,
        };
    }

    public function edgeTypeTo(self $target): IntelligenceGraphEdgeType
    {
        return match ([$this, $target]) {
            [self::OBSERVATION, self::SIGNAL] => IntelligenceGraphEdgeType::EVIDENCES,
            [self::SIGNAL, self::INSIGHT] => IntelligenceGraphEdgeType::INFORMS,
            [self::INSIGHT, self::RECOMMENDATION] => IntelligenceGraphEdgeType::RECOMMENDS,
            [self::RECOMMENDATION, self::ACTION] => IntelligenceGraphEdgeType::ACTS_ON,
            [self::ACTION, self::OUTCOME] => IntelligenceGraphEdgeType::ACHIEVES,
            default => IntelligenceGraphEdgeType::DERIVES_FROM,
        };
    }

    public function precedes(self $stage): bool
    {
        return array_search($this, self::ordered(), true) < array_search($stage, self::ordered(), true);
    }

    public function next(): ?self
    {
        $stages = self::ordered();
        $index = array_search($this, $stages, true);

        return $index === false ? null : ($stages[$index + 1] ?? null);
    }
}
