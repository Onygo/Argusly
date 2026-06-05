<?php

namespace App\Support;

use App\Enums\SupportedLanguage;

/**
 * Basic heuristic-based language detection for Dutch vs English content.
 *
 * This is a lightweight detector for common cases. For production-grade
 * detection, consider integrating a proper NLP library.
 */
class LanguageDetector
{
    /**
     * Common Dutch words that rarely appear in English text.
     *
     * @var array<int, string>
     */
    private const DUTCH_MARKERS = [
        // Articles and pronouns
        'de', 'het', 'een', 'deze', 'dit', 'die', 'dat',
        'zijn', 'haar', 'hun', 'ons', 'onze', 'mijn', 'jouw', 'uw',
        'zij', 'wij', 'jullie', 'hij', 'ik', 'jij', 'je', 'u',

        // Common verbs
        'is', 'zijn', 'was', 'waren', 'wordt', 'worden', 'werd', 'werden',
        'heeft', 'hebben', 'had', 'hadden', 'kan', 'kunnen', 'kon', 'konden',
        'moet', 'moeten', 'moest', 'moesten', 'zal', 'zullen', 'zou', 'zouden',
        'mag', 'mogen', 'wil', 'willen', 'gaat', 'gaan', 'ging', 'gingen',
        'komt', 'komen', 'kwam', 'kwamen', 'doet', 'doen', 'deed', 'deden',
        'maakt', 'maken', 'maakte', 'maakten', 'geeft', 'geven', 'gaf', 'gaven',
        'zegt', 'zeggen', 'zei', 'zeiden', 'vindt', 'vinden', 'vond', 'vonden',

        // Conjunctions and prepositions
        'en', 'of', 'maar', 'want', 'dus', 'omdat', 'hoewel', 'terwijl',
        'als', 'dan', 'toen', 'voordat', 'nadat', 'zodat', 'tenzij',
        'van', 'voor', 'naar', 'met', 'bij', 'door', 'over', 'onder',
        'tussen', 'zonder', 'tegen', 'langs', 'rond', 'om', 'uit', 'in',
        'op', 'aan', 'tot', 'vanaf', 'tijdens', 'binnen', 'buiten',

        // Common adverbs
        'niet', 'ook', 'nog', 'al', 'wel', 'zeer', 'veel', 'weinig',
        'altijd', 'nooit', 'soms', 'vaak', 'zelden', 'hier', 'daar',
        'nu', 'dan', 'straks', 'later', 'eerder', 'vandaag', 'morgen', 'gisteren',
        'hoe', 'wat', 'waar', 'wanneer', 'waarom', 'wie', 'welke', 'welk',

        // Common nouns
        'jaar', 'jaren', 'dag', 'dagen', 'week', 'weken', 'maand', 'maanden',
        'uur', 'uren', 'minuut', 'minuten', 'tijd', 'werk', 'werken',
        'mensen', 'mens', 'kind', 'kinderen', 'vrouw', 'man', 'vrouwen', 'mannen',
        'huis', 'huizen', 'stad', 'steden', 'land', 'landen', 'wereld',
        'vraag', 'vragen', 'antwoord', 'antwoorden', 'probleem', 'problemen',
        'oplossing', 'oplossingen', 'voorbeeld', 'voorbeelden',

        // Common adjectives
        'groot', 'grote', 'klein', 'kleine', 'nieuw', 'nieuwe', 'oud', 'oude',
        'goed', 'goede', 'slecht', 'slechte', 'mooi', 'mooie', 'lelijk', 'lelijke',
        'belangrijk', 'belangrijke', 'eerste', 'laatste', 'volgende', 'vorige',

        // Dutch-specific character sequences (diacritics patterns)
        'één', 'twéé', 'drié',
    ];

    /**
     * Common English words that rarely appear in Dutch text.
     *
     * @var array<int, string>
     */
    private const ENGLISH_MARKERS = [
        // Articles and pronouns
        'the', 'a', 'an', 'this', 'that', 'these', 'those',
        'his', 'her', 'their', 'our', 'my', 'your', 'its',
        'she', 'we', 'they', 'he', 'i', 'you', 'it',

        // Common verbs
        'is', 'are', 'was', 'were', 'been', 'being', 'be',
        'has', 'have', 'had', 'having', 'do', 'does', 'did', 'doing', 'done',
        'can', 'could', 'will', 'would', 'shall', 'should', 'may', 'might', 'must',
        'get', 'gets', 'got', 'getting', 'make', 'makes', 'made', 'making',
        'go', 'goes', 'went', 'going', 'gone', 'come', 'comes', 'came', 'coming',
        'take', 'takes', 'took', 'taking', 'taken', 'give', 'gives', 'gave', 'giving',
        'see', 'sees', 'saw', 'seeing', 'seen', 'know', 'knows', 'knew', 'knowing',
        'think', 'thinks', 'thought', 'thinking', 'want', 'wants', 'wanted', 'wanting',

        // Conjunctions and prepositions
        'and', 'or', 'but', 'because', 'although', 'while', 'when', 'where',
        'if', 'then', 'before', 'after', 'so', 'unless', 'until', 'since',
        'of', 'for', 'to', 'with', 'by', 'from', 'about', 'into', 'through',
        'during', 'including', 'until', 'against', 'among', 'throughout',
        'between', 'without', 'within', 'along', 'following', 'across',

        // Common adverbs
        'not', 'also', 'still', 'already', 'just', 'very', 'much', 'little',
        'always', 'never', 'sometimes', 'often', 'rarely', 'here', 'there',
        'now', 'then', 'soon', 'later', 'earlier', 'today', 'tomorrow', 'yesterday',
        'how', 'what', 'where', 'when', 'why', 'who', 'which',

        // Common nouns
        'year', 'years', 'day', 'days', 'week', 'weeks', 'month', 'months',
        'hour', 'hours', 'minute', 'minutes', 'time', 'work', 'working',
        'people', 'person', 'child', 'children', 'woman', 'man', 'women', 'men',
        'house', 'houses', 'city', 'cities', 'country', 'countries', 'world',
        'question', 'questions', 'answer', 'answers', 'problem', 'problems',
        'solution', 'solutions', 'example', 'examples',

        // Common adjectives
        'big', 'small', 'new', 'old', 'good', 'bad', 'great', 'important',
        'first', 'last', 'next', 'previous', 'best', 'worst', 'better', 'worse',
    ];

    /**
     * Minimum word count threshold for reliable detection.
     */
    private const MIN_WORDS_FOR_DETECTION = 10;

    /**
     * Minimum confidence threshold for detection result.
     */
    private const CONFIDENCE_THRESHOLD = 0.6;

    /**
     * Detect the language of the given text.
     *
     * @return array{language: SupportedLanguage|null, confidence: float, dutch_score: float, english_score: float, word_count: int}
     */
    public function detect(string $text): array
    {
        $text = $this->normalizeText($text);
        $words = $this->extractWords($text);
        $wordCount = count($words);

        if ($wordCount < self::MIN_WORDS_FOR_DETECTION) {
            return [
                'language' => null,
                'confidence' => 0.0,
                'dutch_score' => 0.0,
                'english_score' => 0.0,
                'word_count' => $wordCount,
            ];
        }

        $dutchScore = $this->calculateLanguageScore($words, self::DUTCH_MARKERS);
        $englishScore = $this->calculateLanguageScore($words, self::ENGLISH_MARKERS);

        // Additional heuristics for Dutch
        $dutchScore += $this->detectDutchPatterns($text);

        // Normalize scores
        $totalScore = $dutchScore + $englishScore;
        if ($totalScore === 0.0) {
            return [
                'language' => null,
                'confidence' => 0.0,
                'dutch_score' => 0.0,
                'english_score' => 0.0,
                'word_count' => $wordCount,
            ];
        }

        $dutchRatio = $dutchScore / $totalScore;
        $englishRatio = $englishScore / $totalScore;

        $detectedLanguage = null;
        $confidence = 0.0;

        if ($dutchRatio > self::CONFIDENCE_THRESHOLD) {
            $detectedLanguage = SupportedLanguage::NL;
            $confidence = $dutchRatio;
        } elseif ($englishRatio > self::CONFIDENCE_THRESHOLD) {
            $detectedLanguage = SupportedLanguage::EN;
            $confidence = $englishRatio;
        }

        return [
            'language' => $detectedLanguage,
            'confidence' => round($confidence, 3),
            'dutch_score' => round($dutchRatio, 3),
            'english_score' => round($englishRatio, 3),
            'word_count' => $wordCount,
        ];
    }

    /**
     * Check if the text appears to be Dutch.
     */
    public function isDutch(string $text, float $minConfidence = 0.6): bool
    {
        $result = $this->detect($text);

        return $result['language'] === SupportedLanguage::NL
            && $result['confidence'] >= $minConfidence;
    }

    /**
     * Check if the text appears to be English.
     */
    public function isEnglish(string $text, float $minConfidence = 0.6): bool
    {
        $result = $this->detect($text);

        return $result['language'] === SupportedLanguage::EN
            && $result['confidence'] >= $minConfidence;
    }

    /**
     * Detect if content locale is mismatched with actual content language.
     *
     * @return array{is_mismatched: bool, declared_locale: string, detected_language: SupportedLanguage|null, confidence: float, suggested_locale: string|null}
     */
    public function detectMismatch(string $text, string $declaredLocale): array
    {
        $result = $this->detect($text);
        $normalizedDeclared = strtolower(trim($declaredLocale));

        $isMismatched = false;
        $suggestedLocale = null;

        if ($result['language'] !== null && $result['confidence'] >= self::CONFIDENCE_THRESHOLD) {
            $detectedLocale = $result['language']->value;

            if ($normalizedDeclared !== $detectedLocale) {
                $isMismatched = true;
                $suggestedLocale = $detectedLocale;
            }
        }

        return [
            'is_mismatched' => $isMismatched,
            'declared_locale' => $normalizedDeclared,
            'detected_language' => $result['language'],
            'confidence' => $result['confidence'],
            'suggested_locale' => $suggestedLocale,
        ];
    }

    /**
     * Normalize text for analysis.
     */
    private function normalizeText(string $text): string
    {
        // Strip HTML tags
        $text = strip_tags($text);

        // Normalize whitespace
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        // Convert to lowercase
        $text = mb_strtolower(trim($text), 'UTF-8');

        return $text;
    }

    /**
     * Extract words from normalized text.
     *
     * @return array<int, string>
     */
    private function extractWords(string $text): array
    {
        // Split on non-letter characters, preserving diacritics
        $words = preg_split('/[^a-záàâäãåçéèêëíìîïñóòôöõúùûüýÿœæ]+/iu', $text, -1, PREG_SPLIT_NO_EMPTY);

        return is_array($words) ? $words : [];
    }

    /**
     * Calculate language score based on marker word frequency.
     *
     * @param array<int, string> $words
     * @param array<int, string> $markers
     */
    private function calculateLanguageScore(array $words, array $markers): float
    {
        $markerSet = array_flip($markers);
        $matchCount = 0;

        foreach ($words as $word) {
            if (isset($markerSet[$word])) {
                $matchCount++;
            }
        }

        // Return ratio of marker matches to total words
        return count($words) > 0 ? ($matchCount / count($words)) : 0.0;
    }

    /**
     * Detect Dutch-specific patterns (diacritics, character combinations).
     */
    private function detectDutchPatterns(string $text): float
    {
        $score = 0.0;

        // Dutch diacritics: ë, é, ï in common patterns
        if (preg_match('/[ëéï]/u', $text)) {
            $score += 0.05;
        }

        // Common Dutch digraphs
        $dutchDigraphs = ['ij', 'oe', 'ou', 'au', 'ei', 'ui', 'eu', 'ie', 'aa', 'ee', 'oo', 'uu'];
        foreach ($dutchDigraphs as $digraph) {
            if (str_contains($text, $digraph)) {
                $score += 0.01;
            }
        }

        // Dutch-specific endings
        $dutchEndings = ['-heid', '-lijk', '-ing', '-schap', '-isme', '-ist', '-eren', '-atie'];
        foreach ($dutchEndings as $ending) {
            if (str_contains($text, $ending)) {
                $score += 0.02;
            }
        }

        return $score;
    }
}
