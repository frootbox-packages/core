<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Tests\Page;

use Frootbox\Core\Action\AccessDeniedException;
use Frootbox\Core\Action\Action;
use Frootbox\Core\Action\Channel;
use Frootbox\Core\Action\Context;
use Frootbox\Core\Action\InvalidInputException;
use Frootbox\Core\Action\Undo\UndoAction;
use Frootbox\Core\Auth\Actor;
use Frootbox\Core\Auth\Gate;
use Frootbox\Core\Auth\RolePermissions;
use Frootbox\Core\Auth\Scope;
use Frootbox\Core\Auth\ScopeResolvers;
use Frootbox\Core\Component\ListLayouts;
use Frootbox\Core\Extension\Extensions;
use Frootbox\Core\Navigation\UpdateNavigation;
use Frootbox\Core\Page\CreatePage;
use Frootbox\Core\Page\DeletePage;
use Frootbox\Core\Page\LockPage;
use Frootbox\Core\Page\MovePage;
use Frootbox\Core\Page\PageScopes;
use Frootbox\Core\Page\PageTreeNode;
use Frootbox\Core\Page\PublishPages;
use Frootbox\Core\Page\ShowPage;
use Frootbox\Core\Page\ShowPageTree;
use Frootbox\Core\Page\UpdatePage;
use Frootbox\Core\Tests\Support\FixtureSite;
use Frootbox\Core\Tests\Support\NotesTestCase;
use Frootbox\Core\User\AssignRole;
use Frootbox\Core\User\CreateUser;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(Gate::class)]
#[CoversClass(Scope::class)]
#[CoversClass(ScopeResolvers::class)]
#[CoversClass(PageScopes::class)]
#[CoversClass(RolePermissions::class)]
#[CoversClass(ShowPageTree::class)]
final class PageBranchesTest extends NotesTestCase
{
    private string $start;

    private string $about;

    private string $team;

    private string $contact;

    private Context $editor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->start = $this->createPage(null, 'Start');
        $this->about = $this->createPage($this->start, 'Über uns');
        $this->team = $this->createPage($this->about, 'Team');
        $this->contact = $this->createPage($this->start, 'Kontakt');
        $this->runner()->run(new PublishPages([$this->start, $this->about, $this->team, $this->contact]));

        $user = $this->runner()->run(new CreateUser('jane@example.org', 'Jane', 'kaffeebohne42'))->user;
        $this->runner()->run(new AssignRole($user->id, 'editor', 'page:' . $this->about));
        $this->editor = new Context(Actor::user($user->id, $user->name), Channel::Api);
    }

    public function testChangesPagesOnlyWithinTheBranch(): void
    {
        $this->allowed(new UpdatePage($this->about, layout: 'acme/base/Page'));
        $this->allowed(new UpdatePage($this->team, layout: 'acme/base/Page'));
        $this->denied(new UpdatePage($this->contact, layout: 'acme/base/Page'));

        $new = $this->allowed(new CreatePage('acme/base/Page', ['de' => ['title' => 'Neu']], $this->about))->id;
        $this->denied(new CreatePage('acme/base/Page', ['de' => ['title' => 'Neu']], $this->start));
        $this->denied(new CreatePage('acme/base/Page', ['de' => ['title' => 'Neu']]));

        $this->allowed(new MovePage($new, $this->team));
        $this->denied(new MovePage($new, $this->start));
        $this->denied(new MovePage($this->about, $this->start, 0), 'The top of the branch stays in place.');

        $this->allowed(new PublishPages([$this->team]));
        $this->denied(new PublishPages([$this->team, $this->contact]), 'Every page counts.');
        $this->allowed(new LockPage($this->about));

        $this->allowed(new DeletePage($new));
        $this->denied(new DeletePage($this->about), 'Only from above the branch.');
        $this->denied(new UpdateNavigation('main', ['de' => []]), 'Roles within a branch count only for pages.');
    }

    public function testRejectsBadInputBeforeTheTargets(): void
    {
        $this->expectException(InvalidInputException::class);

        $this->runner()->call('page.update', ['id' => 'nope'], $this->editor);
    }

    public function testShowsTheBranchWithTheWayToIt(): void
    {
        $tree = $this->runner()->ask(new ShowPageTree(), $this->editor);

        self::assertSame([$this->start], array_map(static fn(PageTreeNode $node): string => $node->id, $tree), 'Other branches are left out.');
        self::assertTrue($tree[0]->outside, 'The start page is only the way to the branch.');
        self::assertCount(1, $tree[0]->children);
        self::assertSame($this->about, $tree[0]->children[0]->id);
        self::assertFalse($tree[0]->children[0]->outside);
        self::assertFalse($tree[0]->children[0]->children[0]->outside);

        $all = $this->runner()->ask(new ShowPageTree());
        self::assertCount(2, $all[0]->children);
        self::assertFalse($all[0]->outside);

        self::assertSame($this->team, $this->runner()->ask(new ShowPage($this->team), $this->editor)->id);
        self::assertNotEmpty($this->runner()->ask(new ListLayouts(), $this->editor));

        try {
            $this->runner()->ask(new ShowPage($this->contact), $this->editor);
            self::fail('Pages outside the branch stay closed.');
        } catch (AccessDeniedException) {
            $this->addToAssertionCount(1);
        }
    }

    public function testUndoesWithTheTargetsAsTheyAreNow(): void
    {
        $this->allowed(new UpdatePage($this->team, layout: 'acme/base/Page'));
        $update = $this->lastEntry()->id;
        $this->allowed(new CreatePage('acme/base/Page', ['de' => ['title' => 'Neu']], $this->about));
        $create = $this->lastEntry()->id;

        $this->allowed(new UndoAction($create));

        $this->runner()->run(new MovePage($this->team, $this->contact));
        $this->expectException(AccessDeniedException::class);
        $this->runner()->run(new UndoAction($update), $this->editor);
    }

    protected function extensions(): Extensions
    {
        return FixtureSite::extensions('base', 'site');
    }

    protected function config(): array
    {
        return ['app' => ['url' => 'https://example.org'], 'theme' => 'acme/site', 'languages' => ['de' => ['title' => 'Deutsch']]];
    }

    /**
     * @template TResult
     *
     * @param Action<TResult> $action
     *
     * @return TResult
     */
    private function allowed(Action $action): mixed
    {
        return $this->runner()->run($action, $this->editor);
    }

    /**
     * @param Action<mixed> $action
     */
    private function denied(Action $action, string $message = ''): void
    {
        try {
            $this->runner()->run($action, $this->editor);
            self::fail(\sprintf('%s was allowed. %s', $action::class, $message));
        } catch (AccessDeniedException) {
            self::assertSame('denied', $this->lastEntry()->status->value, $message);
        }
    }

    private function createPage(?string $parentId, string $title): string
    {
        return $this->runner()->run(new CreatePage('acme/base/Page', ['de' => ['title' => $title, 'content' => ['fields' => ['heading' => $title]]]], $parentId))->id;
    }
}
