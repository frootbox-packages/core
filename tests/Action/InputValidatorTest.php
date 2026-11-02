<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Tests\Action;

use Frootbox\Core\Action\InvalidInputException;
use Frootbox\Core\Action\Schema\InputValidator;
use Frootbox\Core\Support\Message;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(InputValidator::class)]
#[CoversClass(Message::class)]
#[CoversClass(InvalidInputException::class)]
final class InputValidatorTest extends TestCase
{
    private const array SCHEMA = [
        'type' => 'object',
        'additionalProperties' => false,
        'required' => ['email', 'name'],
        'properties' => [
            'email' => ['type' => 'string', 'format' => 'email'],
            'name' => ['type' => 'string', 'minLength' => 2, 'maxLength' => 5],
            'age' => ['type' => ['integer', 'null'], 'minimum' => 18],
            'roles' => ['type' => 'array', 'items' => ['type' => 'string'], 'maxItems' => 1],
            'mode' => ['enum' => ['a', 'b']],
        ],
    ];

    public function testMessagesAreTemplatesAtTheirField(): void
    {
        $messages = new InputValidator()->messages(self::SCHEMA, ['name' => 'x', 'age' => 'old', 'roles' => ['a', 'b'], 'mode' => 'c', 'extra' => 1]);

        self::assertEquals([
            '/email' => new Message('is required.'),
            '/name' => new Message('must be at least {min} characters long.', ['min' => 2]),
            '/age' => new Message('must be a whole number.'),
            '/roles' => new Message('can have at most {max} entries.', ['max' => 1]),
            '/mode' => new Message('is not one of the allowed values.'),
            '/' => new Message('Unknown fields: {fields}', ['fields' => 'extra']),
        ], $messages);
    }

    public function testErrorsAreTheFinishedTexts(): void
    {
        self::assertSame([
            '/email' => 'must be an email address.',
            '/name' => 'must be at most 5 characters long.',
            '/age' => 'must be at least 18.',
        ], new InputValidator()->errors(self::SCHEMA, ['email' => 'nope', 'name' => 'abcdefg', 'age' => 3]));
    }

    public function testTheExceptionKeepsTextAndTemplate(): void
    {
        $error = new InvalidInputException('user.create', ['/email' => 'is already in use.', '/name' => new Message('must be at most {max} characters long.', ['max' => 5])]);

        self::assertSame(['/email' => 'is already in use.', '/name' => 'must be at most 5 characters long.'], $error->errors);
        self::assertSame('must be at most {max} characters long.', $error->messages['/name']->text);
        self::assertSame('Invalid input for "user.create": /email: is already in use.; /name: must be at most 5 characters long.', $error->getMessage());
    }
}
