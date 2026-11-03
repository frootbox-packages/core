<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Tests\Plugin;

use Frootbox\Core\Action\Channel;
use Frootbox\Core\Action\Context;
use Frootbox\Core\Action\CurrentContext;
use Frootbox\Core\Action\InvalidInputException;
use Frootbox\Core\Auth\Actor;
use Frootbox\Core\Extension\Extensions;
use Frootbox\Core\Plugin\CreateSpace;
use Frootbox\Core\Plugin\ListAllSpaces;
use Frootbox\Core\Plugin\ListSpaces;
use Frootbox\Core\Plugin\Space;
use Frootbox\Core\Plugin\SpaceRights;
use Frootbox\Core\Plugin\SpaceScope;
use Frootbox\Core\Plugin\SpaceScopes;
use Frootbox\Core\Plugin\SpaceSummary;
use Frootbox\Core\Tests\Fixtures\Plugin\ItemPlugin;
use Frootbox\Core\Tests\Fixtures\Plugin\SaveItem;
use Frootbox\Core\Tests\Support\FixtureSite;
use Frootbox\Core\Tests\Support\NotesTestCase;
use Frootbox\Core\User\AssignRole;
use Frootbox\Core\User\CreateRole;
use Frootbox\Core\User\CreateUser;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(SpaceScopes::class)]
#[CoversClass(SpaceRights::class)]
#[CoversClass(ListAllSpaces::class)]
#[CoversClass(ListSpaces::class)]
#[CoversClass(AssignRole::class)]
final class SpaceScopesTest extends NotesTestCase
{
    private const string IN_DEFAULT = '01JPLUGINITEM0000000000001';

    private const string IN_TRAINING = '01JPLUGINITEM0000000000002';

    private string $training;

    private Context $editor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->training = $this->runner()->run(new CreateSpace(ItemPlugin::TYPE, 'Ausbildung'))->id;
        $this->runner()->run(new SaveItem(self::IN_DEFAULT, ['de' => 'Koch'], ['de' => 'koch']));
        $this->runner()->run(new SaveItem(self::IN_TRAINING, ['de' => 'Azubi'], ['de' => 'azubi'], space: $this->training));

        $this->runner()->run(new CreateRole('items', 'Items', ['acme/plugin/*']));
        $user = $this->runner()->run(new CreateUser('jane@example.org', 'Jane', 'kaffeebohne42'))->user;
        $this->runner()->run(new AssignRole($user->id, 'items', 'space:' . SpaceScopes::id(ItemPlugin::TYPE, $this->training)));
        $this->editor = new Context(Actor::user($user->id, $user->name), Channel::Api);
    }

    public function testCoversASpaceAndTheObjectsInIt(): void
    {
        $scopes = $this->get(SpaceScopes::class);
        $training = SpaceScopes::id(ItemPlugin::TYPE, $this->training);

        self::assertTrue($scopes->covers($training, SpaceScopes::space(ItemPlugin::TYPE, $this->training)->id, false));
        self::assertFalse($scopes->covers($training, SpaceScopes::space(ItemPlugin::TYPE, Space::DEFAULT)->id, false));
        self::assertTrue($scopes->covers($training, SpaceScopes::object(ItemPlugin::TYPE, self::IN_TRAINING)->id, false));
        self::assertFalse($scopes->covers($training, SpaceScopes::object(ItemPlugin::TYPE, self::IN_DEFAULT)->id, false));
        self::assertFalse($scopes->covers($training, SpaceScopes::object(ItemPlugin::TYPE, '01JPLUGINITEM0000000000009')->id, false), 'An unknown object lies nowhere.');
        self::assertFalse($scopes->covers($training, SpaceScopes::object('acme/other/thing', self::IN_TRAINING)->id, false), 'An unknown type neither.');

        // The draft decides: moved to the default space, it lies there.
        $this->runner()->run(new SaveItem(self::IN_TRAINING, ['de' => 'Azubi'], ['de' => 'azubi']));
        self::assertFalse($scopes->covers($training, SpaceScopes::object(ItemPlugin::TYPE, self::IN_TRAINING)->id, false));
    }

    public function testGivesRolesOnlyForSpacesThatExist(): void
    {
        $user = $this->runner()->run(new CreateUser('max@example.org', 'Max', 'kaffeebohne42'))->user;
        $this->runner()->run(new AssignRole($user->id, 'items', 'space:acme/plugin/item:default'));

        foreach (['space:acme/plugin/item:01JNOSPACE0000000000000000', 'space:acme/other/thing:default', 'space:acme/plugin/item'] as $scope) {
            try {
                $this->runner()->run(new AssignRole($user->id, 'items', $scope));
                self::fail(\sprintf('%s was given.', $scope));
            } catch (InvalidInputException $error) {
                self::assertArrayHasKey('/scope', $error->errors, $scope);
            }
        }
    }

    public function testListsOnlyTheSpacesWithTheRight(): void
    {
        self::assertSame([$this->training], self::ids($this->runner()->ask(new ListSpaces(ItemPlugin::TYPE, 'acme/plugin/item.save'), $this->editor)->items));
        self::assertSame([Space::DEFAULT, $this->training], self::ids($this->runner()->ask(new ListSpaces(ItemPlugin::TYPE), $this->editor)->items), 'Without a permission all.');
        self::assertSame([], self::ids($this->runner()->ask(new ListSpaces(ItemPlugin::TYPE, 'page.update'), $this->editor)->items), 'The role holds no other permission.');
        self::assertSame([Space::DEFAULT, $this->training], self::ids($this->runner()->ask(new ListSpaces(ItemPlugin::TYPE, 'acme/plugin/item.save'))->items), 'A role everywhere sees all.');

        $this->get(CurrentContext::class)->set($this->editor);
        $rights = $this->get(SpaceRights::class);
        self::assertSame([$this->training], $rights->within(ItemPlugin::TYPE, 'acme/plugin/item.save'));
        self::assertTrue($rights->allows(ItemPlugin::TYPE, $this->training, 'acme/plugin/item.save'));
        self::assertFalse($rights->allows(ItemPlugin::TYPE, Space::DEFAULT, 'acme/plugin/item.save'));
    }

    public function testListsAllSpacesAsScopes(): void
    {
        $all = $this->runner()->ask(new ListAllSpaces(), $this->editor);

        self::assertSame(
            [['space:acme/plugin/item:default', 'Items'], ['space:acme/plugin/item:' . $this->training, 'Items · Ausbildung']],
            array_map(static fn(SpaceScope $space): array => [$space->scope, $space->title], $all),
        );
    }

    /**
     * @param list<SpaceSummary> $spaces
     *
     * @return list<string>
     */
    private static function ids(array $spaces): array
    {
        return array_map(static fn(SpaceSummary $space): string => $space->id, $spaces);
    }

    protected function extensions(): Extensions
    {
        return FixtureSite::extensions('base', 'site', 'plugin');
    }
}
