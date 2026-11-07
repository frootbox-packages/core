<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Tests\Api;

use Doctrine\DBAL\Driver\PDO\Exception as PdoException;
use Doctrine\DBAL\Exception\LockWaitTimeoutException;
use Frootbox\Core\Action\AccessDeniedException;
use Frootbox\Core\Action\BusyException;
use Frootbox\Core\Action\InvalidInputException;
use Frootbox\Core\Action\UndoException;
use Frootbox\Core\Action\UnknownActionException;
use Frootbox\Core\Api\RequestInput;
use Frootbox\Core\Error\Problem;
use Frootbox\Core\Http\Exception\HttpException;
use Frootbox\Core\User\UserException;
use Frootbox\Database\EntityNotFoundException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

#[CoversClass(Problem::class)]
#[CoversClass(RequestInput::class)]
final class ProblemTest extends TestCase
{
    /**
     * @return iterable<string, array{Throwable, int, string}>
     */
    public static function errors(): iterable
    {
        yield 'guest' => [new AccessDeniedException('No.', signInRequired: true), 401, 'unauthorized'];
        yield 'user' => [new AccessDeniedException('No.'), 403, 'access-denied'];
        yield 'input' => [new InvalidInputException('note.create', ['/title' => 'Too short']), 422, 'invalid-input'];
        yield 'unknown action' => [UnknownActionException::named('x.y'), 404, 'not-found'];
        yield 'unknown row' => [EntityNotFoundException::for('users', 5), 404, 'not-found'];
        yield 'undo' => [UndoException::notPossible(5, 'no.'), 409, 'conflict'];
        yield 'busy' => [new BusyException('Wait.'), 503, 'busy'];
        yield 'database locked' => [new LockWaitTimeoutException(PdoException::new(new \PDOException('database is locked')), null), 503, 'busy'];
        yield 'other action error' => [UserException::notYourself('delete'), 422, 'unprocessable'];
        yield 'http' => [HttpException::methodNotAllowed('GET', ['POST']), 405, 'method-not-allowed'];
        yield 'anything else' => [new RuntimeException('Secret'), 500, 'server-error'];
    }

    #[DataProvider('errors')]
    public function testMapsErrorsToStatusAndCode(Throwable $error, int $status, string $code): void
    {
        $problem = Problem::from($error, showDetails: true);

        self::assertSame($status, $problem->status);
        self::assertSame($code, $problem->code);
    }

    public function testABusyServerSaysWhenToTryAgain(): void
    {
        $problem = Problem::from(new LockWaitTimeoutException(PdoException::new(new \PDOException('SQLSTATE[HY000]: database is locked')), null), showDetails: false);

        self::assertSame('The website is busy right now. Please try again in a moment.', $problem->detail);
        self::assertSame(['Retry-After' => '3'], $problem->headers);
    }

    public function testHidesServerErrorsOutsideDevelopment(): void
    {
        self::assertSame('Internal Server Error', Problem::from(new RuntimeException('Secret'), showDetails: false)->detail);
        self::assertSame('Secret', Problem::from(new RuntimeException('Secret'), showDetails: true)->detail);
        self::assertSame('Service Unavailable', Problem::from(new HttpException(503, 'Database down'), showDetails: false)->detail);
    }

    public function testBuildsProblemDetails(): void
    {
        $body = Problem::from(new InvalidInputException('note.create', ['/title' => 'Too short', '' => 'Unknown field']), showDetails: false)->toArray();

        self::assertSame('about:blank', $body['type']);
        self::assertSame('Unprocessable Content', $body['title']);
        self::assertSame(422, $body['status']);
        self::assertSame([['pointer' => '/title', 'message' => 'Too short'], ['pointer' => '/', 'message' => 'Unknown field']], $body['errors']);
    }

    public function testCoercesStringsAlongTheSchema(): void
    {
        $schema = ['type' => 'object', 'properties' => [
            'id' => ['type' => 'integer'],
            'price' => ['type' => ['number', 'null']],
            'active' => ['type' => 'boolean'],
            'hidden' => ['type' => 'boolean'],
            'parent' => ['type' => ['integer', 'null']],
            'name' => ['type' => ['string', 'null']],
            'level' => ['enum' => [1, 2, 3]],
            'ids' => ['type' => 'array', 'items' => ['type' => 'integer']],
            'wrong' => ['type' => 'integer'],
        ]];

        $input = RequestInput::coerce($schema, [
            'id' => '5', 'price' => '9.5', 'active' => 'true', 'hidden' => '0', 'parent' => '',
            'name' => '', 'level' => '2', 'ids' => ['1', '2'], 'wrong' => 'abc', 'unknown' => '1',
        ]);

        self::assertSame([
            'id' => 5, 'price' => 9.5, 'active' => true, 'hidden' => false, 'parent' => null,
            'name' => '', 'level' => 2, 'ids' => [1, 2], 'wrong' => 'abc', 'unknown' => '1',
        ], $input);
    }
}
