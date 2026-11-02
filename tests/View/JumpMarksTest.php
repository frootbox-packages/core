<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Tests\View;

use Frootbox\Core\Component\ComponentKind;
use Frootbox\Core\Component\Instance;
use Frootbox\Core\View\JumpMarks;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(JumpMarks::class)]
final class JumpMarksTest extends TestCase
{
    public function testCollectsTheMarksInTheOrderOfThePage(): void
    {
        $layout = Instance::fromArray(['layout' => 'acme/site/Page', 'areas' => ['main' => [
            ['block' => 'acme/site/Section', 'options' => ['jumpMark' => 'Für Agenturen'], 'areas' => ['first' => [
                ['block' => 'acme/site/Section', 'options' => ['jumpMark' => ' Preise ']],
            ]]],
            ['block' => 'acme/site/Text', 'options' => ['jumpMark' => '']],
            ['block' => 'acme/site/Section', 'options' => ['jumpMark' => 'Preise']],
            ['block' => 'acme/site/Section', 'options' => ['jumpMark' => 'Content']],
            ['block' => 'acme/site/Section', 'options' => ['jumpMark' => '!!!']],
        ]]], ComponentKind::Layout);

        $marks = new JumpMarks();
        $marks->collect($layout, 'de');

        self::assertSame([
            ['id' => 'fuer-agenturen', 'label' => 'Für Agenturen'],
            ['id' => 'preise', 'label' => 'Preise'],
            ['id' => 'preise-2', 'label' => 'Preise'],
            ['id' => 'content-2', 'label' => 'Content'],
            ['id' => 'section', 'label' => '!!!'],
        ], $marks->all(), 'Unique on the page, never the target of "Skip to content".');

        $blocks = $layout->areas['main'];
        self::assertSame('fuer-agenturen', $marks->id($blocks[0]));
        self::assertSame('preise', $marks->id($blocks[0]->areas['first'][0]));
        self::assertNull($marks->id($blocks[1]), 'An empty mark is none.');
    }

    public function testStartsAfreshForEachPage(): void
    {
        $marks = new JumpMarks();
        $first = Instance::fromArray(['block' => 'acme/site/Section', 'options' => ['jumpMark' => 'Team']]);
        $marks->collect($first);
        $marks->collect(Instance::fromArray(['block' => 'acme/site/Section', 'options' => ['jumpMark' => 'Team']]));

        self::assertSame([['id' => 'team', 'label' => 'Team']], $marks->all());
        self::assertNull($marks->id($first));
    }

    public function testStandInMarksFillInForABlockAlone(): void
    {
        // Decision 0213: blocks of the design page render one by one.
        $marks = new JumpMarks();
        $marks->standIn([['id' => 'fb-design-tokens', 'label' => 'Tokens']]);
        $marks->collect(Instance::fromArray(['block' => 'acme/site/JumpBar']));
        self::assertSame([['id' => 'fb-design-tokens', 'label' => 'Tokens']], $marks->all());

        $marks->collect(Instance::fromArray(['block' => 'acme/site/Section', 'options' => ['jumpMark' => 'Team']]));
        self::assertSame([['id' => 'team', 'label' => 'Team']], $marks->all(), 'Marks of its own come first.');

        $marks->standIn([]);
        $marks->collect(Instance::fromArray(['block' => 'acme/site/JumpBar']));
        self::assertSame([], $marks->all());
    }
}
