<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Tests\Component;

use Frootbox\Core\Action\Context;
use Frootbox\Core\Action\CurrentContext;
use Frootbox\Core\Component\Component;
use Frootbox\Core\Component\ComponentKind;
use Frootbox\Core\Component\Option;
use Frootbox\Core\Component\OptionChoices;
use Frootbox\Core\Component\Variant;
use Frootbox\Core\Tests\Fixtures\Notes\CreateNote;
use Frootbox\Core\Tests\Support\NotesTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Choices of an option from a query, e.g. the forms (decision 0222).
 */
#[CoversClass(OptionChoices::class)]
#[CoversClass(Option::class)]
final class OptionChoicesTest extends NotesTestCase
{
    public function testFillsTheChoicesFromTheQuery(): void
    {
        $id = $this->runner()->run(new CreateNote('Einkaufsliste'))->id;
        $option = new Option('note', 'choice', 'Note', '', source: 'note.choices');

        $filled = $this->get(OptionChoices::class)->fill($this->component($option))->variants['Default']->options['note'];

        self::assertSame([$id => 'Einkaufsliste'], $filled->choices);
        self::assertSame('note.choices', $filled->source);
        self::assertTrue($option->accepts((string) $id), 'Any id is kept; the block decides what to show.');
        self::assertFalse($option->accepts(5));
        self::assertSame(['type' => 'string', 'maxLength' => 100], $option->valueSchema());
    }

    public function testWithoutTheRightThereAreNoChoices(): void
    {
        $this->runner()->run(new CreateNote('Geheim'));
        $this->get(CurrentContext::class)->set(Context::guest());

        $filled = $this->get(OptionChoices::class)->fill($this->component(new Option('note', 'choice', 'Note', '', source: 'note.choices')));

        self::assertSame([], $filled->variants['Default']->options['note']->choices);
    }

    private function component(Option $option): Component
    {
        return new Component(ComponentKind::Block, 'acme/notes/Note', '/tmp', 'Note', '', [], [], ['Default' => new Variant('Default', 'Default', '', ['note' => $option], 'Default.twig', 'acme/notes')]);
    }
}
