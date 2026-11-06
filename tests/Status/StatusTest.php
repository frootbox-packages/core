<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Tests\Status;

use Frootbox\Core\Action\AccessDeniedException;
use Frootbox\Core\Action\Channel;
use Frootbox\Core\Action\Context;
use Frootbox\Core\Application;
use Frootbox\Core\Auth\AccessTokens;
use Frootbox\Core\Auth\Actor;
use Frootbox\Core\Frootbox;
use Frootbox\Core\Http\MiddlewareDispatcher;
use Frootbox\Core\Http\NotFoundHandler;
use Frootbox\Core\Revision\Revisions;
use Frootbox\Core\Status\ShowStatus;
use Frootbox\Core\Status\SiteStatus;
use Frootbox\Core\Tests\Support\NotesTestCase;
use Frootbox\Core\User\CreateRole;
use Frootbox\Core\User\CreateServiceToken;
use Frootbox\Core\User\CreateUser;
use Frootbox\Core\User\UserKind;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Http\Message\ResponseInterface;

#[CoversClass(ShowStatus::class)]
#[CoversClass(SiteStatus::class)]
#[CoversClass(Revisions::class)]
final class StatusTest extends NotesTestCase
{
    public function testDescribesTheWebsiteWithoutPersonalData(): void
    {
        $this->runner()->run(new CreateUser('jane@example.com', 'Jane Doe', 'kaffeebohne42', ['admin']));
        $this->connection()->insert('revisions', [
            'type' => 'page',
            'entity_id' => '01JTESTPAGE0000000000000000',
            'status' => 'draft',
            'data' => '{"title":"Secret plans"}',
            'created_at' => '2026-11-05 09:30:00',
            'updated_at' => '2026-11-05 10:15:00',
        ]);

        $status = $this->runner()->ask(new ShowStatus());

        self::assertSame(1, $status->schema);
        self::assertSame(['name' => 'frootbox', 'version' => Frootbox::version()], $status->system);
        self::assertSame(\PHP_VERSION, $status->php['version']);
        self::assertNotNull($status->database);
        self::assertContains($status->database['kind'], ['sqlite', 'mysql', 'mariadb']);
        self::assertSame(['due' => 0, 'failed' => 0], $status->queue);
        self::assertSame(1, $status->drafts);
        self::assertSame(0, $status->comments);
        self::assertNotNull($status->lastEdit);
        self::assertStringStartsWith('2026-11-05T10:15:00', $status->lastEdit);
        self::assertNotNull($status->storage['free']);

        $json = json_encode($status, \JSON_THROW_ON_ERROR);
        foreach (['jane@example.com', 'Jane Doe', 'Secret plans'] as $private) {
            self::assertStringNotContainsString($private, $json);
        }
    }

    public function testAServiceAccountReadsTheStatusOverTheApiAndNothingElse(): void
    {
        $this->runner()->run(new CreateRole('status-reader', 'Status', ['status.show']), Context::system());
        $service = $this->runner()->run(new CreateUser(null, 'frootbox.de', roles: ['status-reader'], kind: UserKind::Service), Context::system())->user;
        $token = $this->runner()->run(new CreateServiceToken($service->id, 'Status'), Context::system())->token;

        $response = $this->call('/api/v1/status.show', $token);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $body = json_decode((string) $response->getBody(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertIsArray($body['data']);
        self::assertSame(1, $body['data']['schema']);
        self::assertSame(['name' => 'frootbox', 'version' => Frootbox::version()], $body['data']['system']);

        self::assertSame(403, $this->call('/api/v1/user.list', $token)->getStatusCode());
        self::assertNotNull($this->get(AccessTokens::class)->forUser($service->id)[0]->lastUsedAt, 'The last call shows when frootbox.de asked.');
    }

    public function testEditorsDoNotSeeIt(): void
    {
        $editor = $this->runner()->run(new CreateUser('ed@example.com', 'Ed', 'kaffeebohne42', ['editor']))->user;

        $this->expectException(AccessDeniedException::class);
        $this->runner()->ask(new ShowStatus(), new Context(Actor::user($editor->id, $editor->name), Channel::Api));
    }

    public function testWithoutTheFunctionsForDiskSpaceItKnowsNone(): void
    {
        self::assertIsInt(ShowStatus::space('disk_total_space', __DIR__));
        // Like disable_functions on webspace: the function is not there at all.
        self::assertNull(ShowStatus::space('frootbox_disabled_function', __DIR__), 'Not a failing status page.');
        self::assertNull(ShowStatus::space('disk_total_space', __DIR__ . '/missing'));
    }

    private function call(string $path, string $token): ResponseInterface
    {
        $factory = new Psr17Factory();
        $request = $factory->createServerRequest('GET', 'http://localhost' . $path)->withHeader('Authorization', 'Bearer ' . $token);
        $middleware = array_map($this->get(...), Application::MIDDLEWARE);

        return new MiddlewareDispatcher($middleware, $this->get(NotFoundHandler::class))->handle($request);
    }
}
