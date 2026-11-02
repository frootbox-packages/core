<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Search;

/**
 * A search source whose items come from files rather than from actions,
 * e.g. the help (decision 0218). When its fingerprint changes, the index
 * reads the source anew before the next search.
 */
interface Fingerprinted
{
    public function fingerprint(): string;
}
