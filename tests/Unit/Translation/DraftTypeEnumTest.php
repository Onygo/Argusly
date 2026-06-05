<?php

namespace Tests\Unit\Translation;

use App\Enums\DraftType;
use PHPUnit\Framework\TestCase;

class DraftTypeEnumTest extends TestCase
{
    public function test_all_draft_types_exist(): void
    {
        $values = DraftType::values();

        $this->assertContains('original', $values);
        $this->assertContains('translation', $values);
        $this->assertContains('hybrid', $values);
        $this->assertCount(3, $values);
    }

    public function test_default_type_is_original(): void
    {
        $this->assertSame(DraftType::ORIGINAL, DraftType::default());
    }

    public function test_is_translation_returns_correctly(): void
    {
        $this->assertFalse(DraftType::ORIGINAL->isTranslation());
        $this->assertTrue(DraftType::TRANSLATION->isTranslation());
        $this->assertFalse(DraftType::HYBRID->isTranslation());
    }

    public function test_is_original_returns_correctly(): void
    {
        $this->assertTrue(DraftType::ORIGINAL->isOriginal());
        $this->assertFalse(DraftType::TRANSLATION->isOriginal());
        $this->assertFalse(DraftType::HYBRID->isOriginal());
    }

    public function test_is_hybrid_returns_correctly(): void
    {
        $this->assertFalse(DraftType::ORIGINAL->isHybrid());
        $this->assertFalse(DraftType::TRANSLATION->isHybrid());
        $this->assertTrue(DraftType::HYBRID->isHybrid());
    }

    public function test_can_be_translated_returns_correctly(): void
    {
        $this->assertTrue(DraftType::ORIGINAL->canBeTranslated());
        $this->assertFalse(DraftType::TRANSLATION->canBeTranslated());
        $this->assertTrue(DraftType::HYBRID->canBeTranslated());
    }

    public function test_all_types_have_labels(): void
    {
        $this->assertSame('Original', DraftType::ORIGINAL->label());
        $this->assertSame('Translation', DraftType::TRANSLATION->label());
        $this->assertSame('Hybrid', DraftType::HYBRID->label());
    }

    public function test_all_types_have_descriptions(): void
    {
        foreach (DraftType::cases() as $type) {
            $this->assertNotEmpty($type->description());
        }
    }
}
