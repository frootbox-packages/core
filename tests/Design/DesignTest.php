<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Tests\Design;

use Frootbox\Core\Action\AccessDeniedException;
use Frootbox\Core\Action\Channel;
use Frootbox\Core\Action\Context;
use Frootbox\Core\Action\InvalidInputException;
use Frootbox\Core\Action\Undo\UndoAction;
use Frootbox\Core\Auth\Actor;
use Frootbox\Core\Cache\ClearPageCache;
use Frootbox\Core\Cache\PageCache;
use Frootbox\Core\Config\Environment;
use Frootbox\Core\Design\Contrast;
use Frootbox\Core\Design\DesignDetails;
use Frootbox\Core\Design\DesignEditor;
use Frootbox\Core\Design\DesignTokens;
use Frootbox\Core\Design\DiscardDesign;
use Frootbox\Core\Design\ListDesignVersions;
use Frootbox\Core\Design\PublishDesign;
use Frootbox\Core\Design\RestoreDesign;
use Frootbox\Core\Design\ShowDesign;
use Frootbox\Core\Design\TokenDetails;
use Frootbox\Core\Design\UpdateDesign;
use Frootbox\Core\Extension\Extensions;
use Frootbox\Core\Page\CreatePage;
use Frootbox\Core\Page\EditMode;
use Frootbox\Core\Page\PageHandler;
use Frootbox\Core\Page\PublishPages;
use Frootbox\Core\Tests\Support\FixtureSite;
use Frootbox\Core\Tests\Support\NotesTestCase;
use Frootbox\Core\User\CreateUser;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(DesignEditor::class)]
#[CoversClass(DesignTokens::class)]
#[CoversClass(Contrast::class)]
#[CoversClass(ShowDesign::class)]
#[CoversClass(UpdateDesign::class)]
#[CoversClass(PublishDesign::class)]
#[CoversClass(DiscardDesign::class)]
#[CoversClass(RestoreDesign::class)]
#[CoversClass(ListDesignVersions::class)]
#[CoversClass(ClearPageCache::class)]
final class DesignTest extends NotesTestCase
{
    public function testListsTheTokensEditorsMayChange(): void
    {
        $design = $this->runner()->ask(new ShowDesign());

        self::assertSame('acme/site', $design->theme);
        self::assertFalse($design->draft);
        self::assertNull($design->version);
        self::assertSame(['color-primary', 'font-heading', 'radius', 'space'], array_map(static fn(TokenDetails $token): string => $token->name, $design->tokens));

        $primary = $this->token($design, 'color-primary');
        self::assertSame(['color', 'Main colour', '#c00', '#cc0000', '#c00', '#c00'], [$primary->type, $primary->label, $primary->value, $primary->css, $primary->published, $primary->default]);
        self::assertSame(['token' => 'color-text', 'ratio' => 2.7, 'enough' => false], $primary->contrast);

        $font = $this->token($design, 'font-heading');
        self::assertSame(['sans', 'serif', 'rounded', 'mono', 'lora'], array_column($font->options, 'value'));
        self::assertSame('"Lora", serif', $font->options[4]['css']);
        self::assertSame([['value' => 'normal', 'label' => 'Normal', 'css' => '2rem'], ['value' => 'wide', 'label' => 'Wide', 'css' => '4rem']], $this->token($design, 'space')->options);
        self::assertSame([0.0, 20.0, 'px'], [$this->token($design, 'radius')->min, $this->token($design, 'radius')->max, $this->token($design, 'radius')->unit]);
    }

    public function testChangesTheDraftAndPublishesIt(): void
    {
        $design = $this->runner()->run(new UpdateDesign(['color-primary' => '#FFF', 'font-heading' => 'lora']));

        self::assertTrue($design->draft);
        $primary = $this->token($design, 'color-primary');
        self::assertSame(['#ffffff', '#c00'], [$primary->value, $primary->published], 'Colours are stored with six lower case digits.');
        self::assertSame(['token' => 'color-text', 'ratio' => 15.91, 'enough' => true], $primary->contrast);
        self::assertSame('lora', $this->token($design, 'font-heading')->value);

        // Visitors keep the published design, the editing mode shows the draft.
        $tokens = $this->get(DesignTokens::class);
        self::assertStringContainsString('--color-primary:#cc0000;', $tokens->css());
        $this->get(EditMode::class)->set(true);
        self::assertStringContainsString('--color-primary:#ffffff;', $tokens->css());
        self::assertStringContainsString('--font-heading:"Lora", serif;', $tokens->css());
        $this->get(EditMode::class)->set(false);

        $design = $this->runner()->run(new PublishDesign());
        self::assertFalse($design->draft);
        self::assertSame(1, $design->version);
        self::assertSame('#ffffff', $this->token($design, 'color-primary')->published);
        self::assertStringContainsString('--color-primary:#ffffff;', $tokens->css());

        $this->assertInvalid(fn() => $this->runner()->run(new PublishDesign()), '');
        $this->assertInvalid(fn() => $this->runner()->run(new DiscardDesign()), '');
    }

    public function testGoesBackToTheThemeAndDropsADraftWithoutChanges(): void
    {
        $this->runner()->run(new UpdateDesign(['radius' => '10px']));
        $this->runner()->run(new PublishDesign());

        $design = $this->runner()->run(new UpdateDesign(['radius' => null, 'space' => 'wide']));
        self::assertTrue($design->draft);
        self::assertSame(['4px', '10px'], [$this->token($design, 'radius')->value, $this->token($design, 'radius')->published]);

        $design = $this->runner()->run(new UpdateDesign(['radius' => '10px', 'space' => null]));
        self::assertFalse($design->draft, 'The same values as published need no draft.');

        $this->runner()->run(new UpdateDesign(['space' => 'wide']));
        $design = $this->runner()->run(new DiscardDesign());
        self::assertFalse($design->draft);
        self::assertSame('normal', $this->token($design, 'space')->value);
    }

    public function testExplainsValuesThatDoNotFit(): void
    {
        try {
            $this->runner()->run(new UpdateDesign(['color-primary' => 'red', 'radius' => '30px', 'color-text' => '#000', 'width' => '1px', 'space' => 'normal']));
            self::fail('The values were taken.');
        } catch (InvalidInputException $error) {
            self::assertSame(['/tokens/color-primary', '/tokens/radius', '/tokens/color-text', '/tokens/width'], array_keys($error->errors));
            self::assertSame('must be between 0 and 20 px.', $error->errors['/tokens/radius']);
            self::assertSame('The theme has no token "color-text" to change.', $error->errors['/tokens/color-text']);
        }

        self::assertFalse($this->runner()->ask(new ShowDesign())->draft, 'Nothing is saved when one value is wrong.');
    }

    public function testRestoresAnEarlierVersion(): void
    {
        $this->runner()->run(new UpdateDesign(['color-primary' => '#111111']));
        $this->runner()->run(new PublishDesign());
        $this->runner()->run(new UpdateDesign(['color-primary' => '#222222']));
        $this->runner()->run(new PublishDesign());

        $versions = $this->runner()->ask(new ListDesignVersions());
        self::assertSame([2, 1], array_map(static fn($version): int => $version->version, $versions));
        self::assertSame([true, false], array_map(static fn($version): bool => $version->current, $versions));

        $design = $this->runner()->run(new RestoreDesign(1));
        self::assertTrue($design->draft);
        self::assertSame(['#111111', '#222222'], [$this->token($design, 'color-primary')->value, $this->token($design, 'color-primary')->published]);

        $this->assertInvalid(fn() => $this->runner()->run(new RestoreDesign(9)), '/version');
    }

    public function testCanBeUndoneAndClearsThePageCache(): void
    {
        $start = $this->runner()->run(new CreatePage('acme/base/Page', ['de' => ['title' => 'Start', 'content' => ['fields' => ['heading' => 'Start']]]]));
        $this->runner()->run(new PublishPages([$start->id]));
        self::assertStringContainsString('--radius:4px;', $this->page());

        $this->runner()->run(new UpdateDesign(['radius' => '16px']));
        $cached = $this->get(PageHandler::class)->handle(new ServerRequest('GET', 'https://example.org/'));
        self::assertSame('hit', $cached->getHeaderLine(PageCache::HEADER), 'A draft keeps the cached page.');
        $this->runner()->run(new PublishDesign());
        self::assertStringContainsString('--radius:16px;', $this->page(), 'The cached page is gone.');

        $this->runner()->run(new UndoAction($this->lastEntry()->id));
        self::assertStringContainsString('--radius:4px;', $this->page());
        self::assertTrue($this->runner()->ask(new ShowDesign())->draft, 'Undoing the publication brings the draft back.');
    }

    public function testOnlyAdministratorsChangeTheDesignOfTheWholeWebsite(): void
    {
        $admin = $this->context(['admin']);
        $this->runner()->run(new UpdateDesign(['radius' => '6px']), $admin);
        $this->runner()->run(new PublishDesign(), $admin);

        // Decision 0138: editors change pages, not the look of every page.
        $this->expectException(AccessDeniedException::class);
        $this->runner()->run(new UpdateDesign(['radius' => '8px']), $this->context(['editor']));
    }

    public function testComparesColours(): void
    {
        self::assertSame(21.0, Contrast::ratio('#000000', '#ffffff'));
        self::assertSame(1.0, Contrast::ratio('#8a4b2a', '#8a4b2a'));
        self::assertSame(6.73, Contrast::ratio('#ffffff', '#8a4b2a'));
    }

    /**
     * Live, so the page cache is in play (decision 0107).
     */
    protected function environment(): Environment
    {
        return Environment::Production;
    }

    protected function extensions(): Extensions
    {
        return FixtureSite::extensions('base', 'site');
    }

    protected function config(): array
    {
        return ['app' => ['url' => 'https://example.org'], 'theme' => 'acme/site', 'languages' => ['de' => ['title' => 'Deutsch']]];
    }

    private function token(DesignDetails $design, string $name): TokenDetails
    {
        foreach ($design->tokens as $token) {
            if ($token->name === $name) {
                return $token;
            }
        }

        self::fail('No token ' . $name);
    }

    private function page(): string
    {
        return (string) $this->get(PageHandler::class)->handle(new ServerRequest('GET', 'https://example.org/'))->getBody();
    }

    /**
     * @param list<string> $roles
     */
    private function context(array $roles): Context
    {
        $user = $this->runner()->run(new CreateUser('design' . \count($roles) . bin2hex(random_bytes(2)) . '@example.com', 'User', roles: $roles), Context::system())->user;

        return new Context(Actor::user($user->id, $user->name), Channel::Api);
    }

    private function assertInvalid(callable $callback, string $pointer): void
    {
        try {
            $callback();
        } catch (InvalidInputException $error) {
            self::assertArrayHasKey($pointer, $error->messages, implode(', ', array_keys($error->messages)));

            return;
        }

        self::fail('Expected invalid input at ' . $pointer);
    }
}
