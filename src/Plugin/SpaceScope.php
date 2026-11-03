<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Plugin;

/**
 * A space as a scope for roles (decision 0245).
 */
final readonly class SpaceScope
{
    public function __construct(
        /** For user.assign-role, e.g. "space:frootbox/jobs/job:default" */
        public string $scope,
        public string $type,
        public string $space,
        /** For editors, e.g. "Jobs · Ausbildung" */
        public string $title,
    ) {}
}
