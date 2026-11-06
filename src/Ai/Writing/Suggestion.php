<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Ai\Writing;

/**
 * A text the AI suggests (decision 0296). Nothing is saved; a person
 * takes it with the action for the field, e.g. page.update-field.
 */
final readonly class Suggestion
{
    public function __construct(
        /** The suggestion as text, for a rich text as HTML */
        public string $text,
        /** The value to save as the type of the field stores it, for a rich text its document */
        public mixed $value,
    ) {}
}
