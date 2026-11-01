<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
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
            $this->runner()->run(new CreateComment($this->page, 'Hallo', 'Spam'), Context::guest());
            self::fail('A guest without a link commented.');
        } catch (AccessDeniedException) {
        }

        self::assertSame(1, $this->runner()->ask(new CountComments(), $this->editor));
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
            '/body' => new CreateComment($this->page, "  \n ", 'Frau Müller'),
            '/authorName' => new CreateComment($this->page, 'Hallo'),
            '/pageId' => new CreateComment('01ARZ3NDEKTSV4RRFFQ69G5FAV', 'Hallo', 'Frau Müller'),
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

        return $this->runner()->run(new CreateComment($this->page, $body, $name, $blockId, $field, language: 'de', path: '/', excerpt: $excerpt, viewport: 1280), $context);
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
