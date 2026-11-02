<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Tests\Comment;

use DateTimeImmutable;
use Frootbox\Core\Action\AccessDeniedException;
use Frootbox\Core\Action\AsAction;
use Frootbox\Core\Action\Channel;
use Frootbox\Core\Action\Context;
use Frootbox\Core\Action\DefinitionException;
use Frootbox\Core\Action\Descriptor;
use Frootbox\Core\Action\InvalidInputException;
use Frootbox\Core\Action\Runner;
use Frootbox\Core\Auth\Actor;
use Frootbox\Core\Auth\TooManyAttemptsException;
use Frootbox\Core\Comment\CommentEditor;
use Frootbox\Core\Comment\Comments;
use Frootbox\Core\Comment\CommentStatus;
use Frootbox\Core\Comment\CommentThread;
use Frootbox\Core\Comment\CountComments;
use Frootbox\Core\Comment\CreateComment;
use Frootbox\Core\Comment\DeleteComment;
use Frootbox\Core\Comment\ListComments;
use Frootbox\Core\Comment\ReopenComment;
use Frootbox\Core\Comment\ReplyToComment;
use Frootbox\Core\Comment\ResolveComment;
use Frootbox\Core\Config\Environment;
use Frootbox\Core\Extension\Extensions;
use Frootbox\Core\Page\CreatePage;
use Frootbox\Core\Preview\CreatePreview;
use Frootbox\Core\Preview\PrunePreviews;
use Frootbox\Core\Tests\Support\FixtureSite;
use Frootbox\Core\Tests\Support\NotesTestCase;
use Frootbox\Core\User\CreateUser;
use Frootbox\Database\EntityNotFoundException;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(CommentEditor::class)]
#[CoversClass(Comments::class)]
#[CoversClass(CreateComment::class)]
#[CoversClass(ReplyToComment::class)]
#[CoversClass(ResolveComment::class)]
#[CoversClass(ReopenComment::class)]
#[CoversClass(DeleteComment::class)]
#[CoversClass(ListComments::class)]
#[CoversClass(CountComments::class)]
#[CoversClass(Runner::class)]
#[CoversClass(Descriptor::class)]
final class CommentsTest extends NotesTestCase
{
    private string $page;

    private Context $editor;

    private Context $customer;

    private Context $other;

    protected function setUp(): void
    {
        parent::setUp();

        $this->page = $this->runner()->run(new CreatePage('acme/base/Page', ['de' => ['title' => 'Start', 'content' => ['fields' => ['heading' => 'Willkommen']]]]))->id;
        $user = $this->runner()->run(new CreateUser('editor@example.org', 'Erika Editor', roles: ['editor']), Context::system())->user;
        $this->editor = new Context(Actor::user($user->id, $user->name), Channel::Web);
        $this->customer = Context::guest(Channel::Web, '203.0.113.7', $this->runner()->run(new CreatePreview('Frau Müller'), $this->editor)->id);
        $this->other = Context::guest(Channel::Web, '203.0.113.8', $this->runner()->run(new CreatePreview('Herr Meier'), $this->editor)->id);
    }

    public function testAGuestWithALinkCommentsOnABlock(): void
    {
        $thread = $this->comment('Bitte das Foto tauschen.', $this->customer, blockId: 'teaser01', field: 'title', excerpt: "  Frisch\n geröstet ");

        self::assertSame(CommentStatus::Open, $thread->status);
        self::assertSame('Start', $thread->pageTitle);
        self::assertSame('teaser01', $thread->blockId);
        self::assertSame('title', $thread->field);
        self::assertSame('Frisch geröstet', $thread->excerpt);
        self::assertSame(1280, $thread->viewport);
        self::assertSame('Frau Müller', $thread->comment->authorName);
        self::assertTrue($thread->comment->guest);

        // Only with a link: a guest without one is not let in.
        try {
            $this->runner()->run(new CreateComment('Hallo', $this->page, 'Spam'), Context::guest());
            self::fail('A guest without a link commented.');
        } catch (AccessDeniedException) {
        }

        self::assertSame(1, $this->runner()->ask(new CountComments(), $this->editor));
    }

    public function testAGuestCommentsOnTheDesignPage(): void
    {
        // Decision 0210: an element of the design page instead of a page.
        $thread = $this->runner()->run(new CreateComment('Der Hero ist zu hoch.', authorName: 'Agentur', language: 'de', path: '/_design?surface=dark', excerpt: 'Frisch geröstet', design: 'frootbox/theme-base/Hero/Split/1'), $this->customer);
        $this->comment('Auf einer Seite.', $this->customer);

        self::assertNull($thread->pageId);
        self::assertNull($thread->pageTitle);
        self::assertSame('frootbox/theme-base/Hero/Split/1', $thread->design);
        self::assertSame('/_design?surface=dark', $thread->path);
        self::assertSame([$thread->id], self::ids($this->runner()->ask(new ListComments(design: true), $this->customer)), 'Only the threads of the design page.');
        self::assertCount(2, $this->runner()->ask(new ListComments(), $this->editor));
        self::assertSame(2, $this->runner()->ask(new CountComments(), $this->editor), 'They count like the others.');

        foreach (['/pageId' => new CreateComment('Wo?', authorName: 'Agentur'), '/design' => new CreateComment('Beides', $this->page, 'Agentur', design: 'tokens')] as $pointer => $action) {
            try {
                $this->runner()->run($action, $this->customer);
                self::fail('Accepted: ' . $pointer);
            } catch (InvalidInputException $error) {
                self::assertArrayHasKey($pointer, $error->errors);
            }
        }
    }

    public function testAGuestCommentsAtAPointAndOnALandmark(): void
    {
        // Decision 0211: a point in a block, and the footer where no block is.
        $point = $this->runner()->run(new CreateComment('Der Button ist zu klein.', $this->page, 'Frau Müller', 'b1', excerpt: 'Zum Shop', pointX: 875, pointY: 120), $this->customer);
        $footer = $this->runner()->run(new CreateComment('Fehlt hier die Adresse?', $this->page, 'Frau Müller', landmark: 'navigation#2:Fußnavigation', pointX: 0, pointY: 1000), $this->customer);
        $design = $this->runner()->run(new CreateComment('Logo größer', authorName: 'Agentur', design: 'page', landmark: 'banner#1'), $this->customer);

        self::assertSame([875, 120, null], [$point->pointX, $point->pointY, $point->landmark]);
        self::assertSame(['navigation#2:Fußnavigation', 0, 1000, null], [$footer->landmark, $footer->pointX, $footer->pointY, $footer->blockId]);
        self::assertSame('banner#1', $design->landmark);

        $wrong = [
            '/landmark' => new CreateComment('Beides', $this->page, 'Frau Müller', 'b1', landmark: 'banner#1'),
            '/landmark ' => new CreateComment('Am Element', authorName: 'Agentur', design: 'tokens', landmark: 'banner#1'),
            '/pointY' => new CreateComment('Halb', $this->page, 'Frau Müller', pointX: 10),
            '/pointX' => new CreateComment('Zu weit', $this->page, 'Frau Müller', pointX: 1001, pointY: 10),
        ];

        foreach ($wrong as $pointer => $action) {
            try {
                $this->runner()->run($action, $this->customer);
                self::fail('Accepted: ' . $pointer);
            } catch (InvalidInputException $error) {
                self::assertArrayHasKey(trim($pointer), $error->errors);
            }
        }
    }

    public function testAGuestSeesOnlyTheThreadsOfTheirLink(): void
    {
        $mine = $this->comment('Text zu lang', $this->customer);
        $this->comment('Andere Farbe?', $this->other);
        $this->comment('Intern: Foto kommt Montag', $this->editor);

        self::assertSame([$mine->id], self::ids($this->runner()->ask(new ListComments(), $this->customer)));
        self::assertCount(3, $this->runner()->ask(new ListComments(), $this->editor), 'Users see all.');

        // Another link learns nothing about the thread, not even that it exists.
        $this->expectException(EntityNotFoundException::class);
        $this->runner()->run(new ReplyToComment($mine->id, 'Ich auch!', 'Herr Meier'), $this->other);
    }

    public function testRepliesResolvesAndReopens(): void
    {
        $thread = $this->comment('Telefonnummer fehlt', $this->customer);
        $this->runner()->run(new ReplyToComment($thread->id, 'Ist ergänzt.'), $this->editor);
        $done = $this->runner()->run(new ResolveComment($thread->id), $this->editor);

        self::assertSame(CommentStatus::Done, $done->status);
        self::assertSame('Erika Editor', $done->resolvedBy);
        self::assertNotNull($done->resolvedAt);

        // The customer sees the reply and the status.
        $seen = $this->runner()->ask(new ListComments(), $this->customer)[0];
        self::assertSame(CommentStatus::Done, $seen->status);
        self::assertSame(['Ist ergänzt.'], array_map(static fn($reply): string => $reply->body, $seen->replies));
        self::assertSame('Erika Editor', $seen->replies[0]->authorName);
        self::assertFalse($seen->replies[0]->guest);

        // A reply of the customer opens it again, replying to a reply as well.
        $again = $this->runner()->run(new ReplyToComment($seen->replies[0]->id, 'Die Vorwahl fehlt noch.', 'Frau Müller'), $this->customer);
        self::assertSame(CommentStatus::Open, $again->status);
        self::assertNull($again->resolvedBy);
        self::assertCount(2, $again->replies);

        // Guests can neither resolve nor delete.
        foreach ([new ResolveComment($thread->id), new DeleteComment($thread->id)] as $action) {
            try {
                $this->runner()->run($action, $this->customer);
                self::fail('A guest ran ' . $action::class);
            } catch (AccessDeniedException) {
            }
        }

        $this->runner()->run(new ResolveComment($thread->id), $this->editor);
        self::assertSame(CommentStatus::Open, $this->runner()->run(new ReopenComment($thread->id), $this->editor)->status);
    }

    public function testChecksTheInput(): void
    {
        $cases = [
            '/body' => new CreateComment("  \n ", $this->page, 'Frau Müller'),
            '/authorName' => new CreateComment('Hallo', $this->page),
            '/pageId' => new CreateComment('Hallo', '01ARZ3NDEKTSV4RRFFQ69G5FAV', 'Frau Müller'),
        ];

        foreach ($cases as $field => $action) {
            try {
                $this->runner()->run($action, $this->customer);
                self::fail('Accepted without ' . $field);
            } catch (InvalidInputException $error) {
                self::assertArrayHasKey($field, $error->errors);
            }
        }

        try {
            $this->runner()->call('comment.create', ['pageId' => $this->page, 'body' => str_repeat('a', 2001), 'authorName' => str_repeat('b', 81)], $this->customer);
            self::fail('Too long was accepted.');
        } catch (InvalidInputException $error) {
            self::assertArrayHasKey('/body', $error->errors);
            self::assertArrayHasKey('/authorName', $error->errors);
        }

        $thread = $this->comment("<b>Fett</b>\u{0007}\r\nZeile zwei", $this->customer);
        self::assertSame("<b>Fett</b>\nZeile zwei", $thread->comment->body, 'Plain text: kept as written, without control characters.');
    }

    public function testThrottlesALinkTo30AnHour(): void
    {
        $thread = $this->comment('Eins', $this->customer);

        for ($i = 2; $i <= 30; ++$i) {
            $this->runner()->run(new ReplyToComment($thread->id, 'Nummer ' . $i, 'Frau Müller'), $this->customer);
        }

        try {
            $this->comment('Einunddreißig', $this->customer);
            self::fail('The 31st comment was accepted.');
        } catch (TooManyAttemptsException $error) {
            self::assertGreaterThan(3000, $error->retryAfter);
            self::assertSame('Too many comments. Try again in {minutes} minutes.', $error->template->text);
        }

        // Other links and users go on.
        $this->comment('Ich darf', $this->other);
        $this->runner()->run(new ReplyToComment($thread->id, 'Wir auch'), $this->editor);

        $this->connection()->update('comments', ['created_at' => new DateTimeImmutable('-61 minutes')->format('Y-m-d H:i:s')], ['preview_link_id' => $this->customer->previewLinkId]);
        $this->comment('Eine Stunde später', $this->customer);
    }

    public function testCommentsStayUntilDeleted(): void
    {
        $thread = $this->comment('Bleibt', $this->customer);
        $this->runner()->run(new ReplyToComment($thread->id, 'Antwort'), $this->editor);

        // The link is pruned, the comment stays without it.
        $this->connection()->update('preview_links', ['expires_at' => new DateTimeImmutable('-31 days')->format('Y-m-d H:i:s')], ['id' => $this->customer->previewLinkId]);
        self::assertSame(1, $this->runner()->run(new PrunePreviews()));
        $kept = $this->runner()->ask(new ListComments(), $this->editor);
        self::assertSame([$thread->id], self::ids($kept));
        self::assertNull($this->connection()->fetchOne('SELECT preview_link_id FROM comments WHERE id = ?', [$thread->id]));

        // Deleting a reply leaves the thread, deleting the thread its replies.
        $reply = $this->runner()->run(new ReplyToComment($thread->id, 'Noch eine'), $this->editor)->replies[1];
        $this->runner()->run(new DeleteComment($reply->id), $this->editor);
        self::assertCount(1, $this->runner()->ask(new ListComments(), $this->editor)[0]->replies);
        $this->runner()->run(new DeleteComment($thread->id), $this->editor);
        self::assertSame([], $this->connection()->fetchFirstColumn('SELECT id FROM comments'));
    }

    public function testAPreviewActionIsNeitherPublicNorForAllSignedIn(): void
    {
        $this->expectException(DefinitionException::class);
        Descriptor::of(PublicPreviewAction::class);
    }

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
        return ['app' => ['name' => 'Testsite', 'url' => 'https://example.org'], 'theme' => 'acme/site', 'languages' => ['de' => ['title' => 'Deutsch']]];
    }

    private function comment(string $body, Context $context, ?string $blockId = null, ?string $field = null, ?string $excerpt = null): CommentThread
    {
        $name = null === $context->previewLinkId ? null : ($context === $this->customer ? 'Frau Müller' : 'Herr Meier');

        return $this->runner()->run(new CreateComment($body, $this->page, $name, $blockId, $field, language: 'de', path: '/', excerpt: $excerpt, viewport: 1280), $context);
    }

    /**
     * @param list<CommentThread> $threads
     *
     * @return list<string>
     */
    private static function ids(array $threads): array
    {
        return array_map(static fn(CommentThread $thread): string => $thread->id, $threads);
    }
}

/**
 * @implements \Frootbox\Core\Action\Action<void>
 */
#[AsAction('test.preview', 'Neither public nor signedIn with preview', public: true, preview: true)]
final readonly class PublicPreviewAction implements \Frootbox\Core\Action\Action
{
    public function handle(): void {}
}
