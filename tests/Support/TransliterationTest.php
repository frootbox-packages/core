<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Tests\Support;

use Frootbox\Core\Page\Slug;
use Frootbox\Core\Support\Transliteration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(Transliteration::class)]
#[CoversClass(Slug::class)]
final class TransliterationTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function titles(): iterable
    {
        yield 'German' => ['Über uns & Größe', 'de', 'ueber-uns-groesse'];
        yield 'German in Austria' => ['Straße zum Schloß', 'de-at', 'strasse-zum-schloss'];
        yield 'German, decomposed' => ["Mu\u{0308}nchen", 'de', 'muenchen'];
        yield 'English' => ['Über uns', 'en', 'uber-uns'];
        yield 'French' => ["L'été de l'Œuvre", 'fr', 'lete-de-loeuvre'];
        yield 'Spanish' => ['Año nuevo en España', 'es', 'ano-nuevo-en-espana'];
        yield 'Catalan' => ['Col·legi de Barça', 'ca', 'collegi-de-barca'];
        yield 'Turkish' => ['İstanbul şehir ığdır', 'tr', 'istanbul-sehir-igdir'];
        yield 'Turkish in capitals' => ['IŞIK ÇİÇEK', 'tr', 'isik-cicek'];
        yield 'Azerbaijani' => ['Bakı Gəncə', 'az', 'baki-gence'];
        yield 'Polish' => ['Łódź Kraków Gdańsk', 'pl', 'lodz-krakow-gdansk'];
        yield 'Czech' => ['Příliš žluťoučký kůň', 'cs', 'prilis-zlutoucky-kun'];
        yield 'Slovak' => ['Ľubovňa Žilina', 'sk', 'lubovna-zilina'];
        yield 'Hungarian' => ['Árvíztűrő tükörfúrógép', 'hu', 'arvizturo-tukorfurogep'];
        yield 'Romanian' => ['Brașov Țară Constanţa', 'ro', 'brasov-tara-constanta'];
        yield 'Croatian' => ['Đakovo Šibenik Čakovec', 'hr', 'djakovo-sibenik-cakovec'];
        yield 'Slovenian' => ['Ljubljana Šoštanj', 'sl', 'ljubljana-sostanj'];
        yield 'Latvian' => ['Rīga Ķekava', 'lv', 'riga-kekava'];
        yield 'Lithuanian' => ['Šiauliai Kėdainiai', 'lt', 'siauliai-kedainiai'];
        yield 'Estonian' => ['Pärnu Võru', 'et', 'parnu-voru'];
        yield 'Finnish' => ['Hämeenlinna Jyväskylä', 'fi', 'hameenlinna-jyvaskyla'];
        yield 'Swedish' => ['Ærø Ålborg Göteborg', 'sv', 'aero-alborg-goteborg'];
        yield 'Danish' => ['Ærø Øresund Ålborg', 'da', 'aeroe-oeresund-aalborg'];
        yield 'Norwegian' => ['Tromsø Bodø', 'nb', 'tromsoe-bodoe'];
        yield 'Icelandic' => ['Þórshöfn Ísland Garðabær', 'is', 'thorshofn-island-gardabaer'];
        yield 'Maltese' => ['Ħamrun Għawdex', 'mt', 'hamrun-ghawdex'];
        yield 'Russian' => ['Москва новости', 'ru', 'moskva-novosti'];
        yield 'Russian with hard and soft signs' => ['Ёлка, объявление, Пермь', 'ru', 'elka-obyavlenie-perm'];
        yield 'Russian, long letters' => ['Щука Хабаровск Цветы Жуковский', 'ru', 'shchuka-khabarovsk-tsvety-zhukovskiy'];
        yield 'Ukrainian' => ['Київ Гребінка', 'uk', 'kyiv-hrebinka'];
        yield 'Ukrainian at the start of words' => ['Юрій Згурівка Їжакевич Єнакієве', 'uk', 'yurii-zghurivka-yizhakevych-yenakiieve'];
        yield 'Ukrainian after an apostrophe' => ["М'ясо Ялта", 'uk', 'miaso-yalta'];
        yield 'Bulgarian' => ['София Щастие Ъгъл', 'bg', 'sofia-shtastie-agal'];
        yield 'Serbian in Cyrillic' => ['Београд Ђоковић Чачак', 'sr', 'beograd-djokovic-cacak'];
        yield 'Macedonian' => ['Скопје Ѓорче Ќерка', 'mk', 'skopje-gjorche-kjerka'];
        yield 'Cyrillic without a language' => ['Москва', '', 'moskva'];
        yield 'Greek' => ['Ελλάδα Αθήνα', 'el', 'ellada-athina'];
        yield 'Greek pairs of letters' => ['Ευρώπη Θεσσαλονίκη Ουρανός Άγγελος', 'el', 'evropi-thessaloniki-ouranos-angelos'];
        yield 'Greek diaeresis' => ['Προϋπόθεση', 'el', 'proypothesi'];
        yield 'Vietnamese' => ['Hà Nội Đà Nẵng', 'vi', 'ha-noi-da-nang'];
        yield 'Mixed' => ['Café 2026', 'de', 'cafe-2026'];
    }

    /**
     * Scripts without rules keep their letters (decision 0158).
     *
     * @return iterable<string, array{string, string, string}>
     */
    public static function scripts(): iterable
    {
        yield 'Arabic' => ['من نحن', 'ar', 'من-نحن'];
        yield 'Arabic without vowel marks and tatweel' => ['مَقْهَى الكتـــاب', 'ar', 'مقهى-الكتاب'];
        yield 'Arabic digits' => ['عرض ٢٠٢٦', 'ar', 'عرض-2026'];
        yield 'Persian half space and digits' => ['خانه‌ها ۱۴۰۵', 'fa', 'خانه-ها-1405'];
        yield 'Hebrew without points' => ['שָׁלוֹם עולם', 'he', 'שלום-עולם'];
        yield 'Marks of the direction' => ["\u{200F}مرحبا\u{200F}", 'ar', 'مرحبا'];
        yield 'Chinese' => ['北京', 'zh', '北京'];
        yield 'Japanese' => ['東京 カフェ', 'ja', '東京-カフェ'];
        yield 'Korean stays in syllables' => ['한국어 페이지', 'ko', '한국어-페이지'];
        yield 'Devanagari keeps its marks' => ['हिन्दी पृष्ठ', 'hi', 'हिन्दी-पृष्ठ'];
        yield 'Armenian in lower case' => ['Երևան', 'hy', 'երևան'];
        yield 'Mixed with Latin' => ['مقهى Bohnenglück', 'ar', 'مقهى-bohnengluck'];
        yield 'Mixed with Cyrillic' => ['Café 北京 Москва', 'de', 'cafe-北京-moskva'];
        yield 'No letters at all' => ['🎉 !', 'de', ''];
    }

    #[DataProvider('scripts')]
    public function testKeepsTheLettersOfOtherScripts(string $title, string $language, string $slug): void
    {
        self::assertSame($slug, Slug::fromTitle($title, $language));
        self::assertTrue('' === $slug || Slug::isValid($slug), $slug);
    }

    public function testChecksSlugs(): void
    {
        self::assertTrue(Slug::isValid('ueber-uns'));
        self::assertTrue(Slug::isValid('من-نحن'));
        self::assertFalse(Slug::isValid('Über'), 'Latin only as a-z.');
        self::assertFalse(Slug::isValid('москва'), 'Cyrillic only as a-z.');
        self::assertFalse(Slug::isValid('a--b'));
        self::assertFalse(Slug::isValid('a b'));
        self::assertFalse(Slug::isValid('١٢'), 'Digits only as 0-9.');
        self::assertFalse(Slug::isValid("خانه\u{200C}ها"), 'No half space.');
        self::assertFalse(Slug::isValid("\u{0928}\u{093F}\u{093C}"[0] . 'x'), 'No broken bytes.');
        self::assertTrue(Slug::isValid(str_repeat('م', Slug::MAX_LENGTH)), 'The length counts letters, not bytes.');
        self::assertFalse(Slug::isValid(str_repeat('م', Slug::MAX_LENGTH + 1)));

        // Typed on a Mac, letters may come split in two.
        $split = "\u{05E9}\u{05C1}a";
        self::assertSame(Slug::normalize($split), \Normalizer::normalize($split, \Normalizer::FORM_C));

        self::assertTrue(Slug::isLatin('en'));
        self::assertFalse(Slug::isLatin('من'));
        self::assertSame('', Slug::latin('من نحن'), 'File names stay in a-z.');
        self::assertSame('ueber-uns', Slug::latin('Über uns', 'de'));
    }

    #[DataProvider('titles')]
    public function testWritesTitlesInLatinLettersByTheRulesOfTheLanguage(string $title, string $language, string $slug): void
    {
        self::assertSame($slug, Slug::fromTitle($title, $language));
    }

    public function testLeavesWhatItHasNoRulesFor(): void
    {
        self::assertSame('kyiv, 北京!', Transliteration::toLatin('Київ, 北京!', 'uk'));
        self::assertSame('a?b', Transliteration::toLatin("a\xFFb"), 'Broken bytes become a question mark.');
        self::assertSame('a-b', Slug::fromTitle("a\xFFb"));
    }
}
