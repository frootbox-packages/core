<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Media;

/**
 * Whether an AI made or changed a medium (decision 0251). Visitors see
 * it as a badge on the image; it comes from IPTC DigitalSourceType in
 * the file, if there.
 */
enum AiOrigin: string
{
    case None = 'none';
    case Generated = 'generated';
    case Edited = 'edited';

    /**
     * The text for visitors, in English as the key for translations.
     */
    public function label(): string
    {
        return match ($this) {
            self::None => '',
            self::Generated => 'Generated with AI',
            self::Edited => 'Edited with AI',
        };
    }

    /**
     * The origin from a stored value, None for an unknown one.
     */
    public static function of(string $value): self
    {
        return self::tryFrom($value) ?? self::None;
    }
}
