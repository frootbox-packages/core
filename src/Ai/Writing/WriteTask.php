<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Ai\Writing;

/**
 * What the AI does with the text of a field (decision 0296).
 */
enum WriteTask: string
{
    /** Says the same in other words. */
    case Rephrase = 'rephrase';

    case Shorten = 'shorten';

    case Expand = 'expand';

    /** Fixes spelling, grammar and punctuation, nothing else. */
    case Correct = 'correct';

    /** Translates the field from another language of the page. */
    case Translate = 'translate';

    /** Follows the instruction of the person, also for an empty field. */
    case Instruct = 'instruct';

    /**
     * What the model is asked to do.
     */
    public function prompt(): string
    {
        return match ($this) {
            self::Rephrase => 'Rephrase the text: the same meaning and about the same length, in fresh, clear words.',
            self::Shorten => 'Shorten the text clearly, to about half, and keep what matters most.',
            self::Expand => 'Expand the text by about half with helpful details that fit the page. Invent no facts, names, numbers or promises.',
            self::Correct => 'Correct spelling, grammar and punctuation only. Change nothing else, not even the style.',
            self::Translate => 'Translate the text. Keep its meaning, tone and formatting; write as a native speaker would, not word by word.',
            self::Instruct => 'Follow the instruction of the person.',
        };
    }
}
