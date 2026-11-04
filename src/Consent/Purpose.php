<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Consent;

/**
 * What a service is for, as visitors see it grouped in the privacy
 * settings (decision 0264).
 */
enum Purpose: string
{
    /** Videos, maps and other content */
    case Media = 'media';

    /** Protection against spam and abuse, e.g. a captcha */
    case Security = 'security';

    /** Features such as a chat or a booking widget */
    case Functional = 'functional';

    /** Counting and analysing visits */
    case Statistics = 'statistics';

    /** Advertising and measuring it */
    case Marketing = 'marketing';

    /**
     * The heading of the group in the privacy settings, in English.
     */
    public function title(): string
    {
        return match ($this) {
            self::Media => 'Media',
            self::Security => 'Security',
            self::Functional => 'Functions',
            self::Statistics => 'Statistics',
            self::Marketing => 'Marketing',
        };
    }
}
