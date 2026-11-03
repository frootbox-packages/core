<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\ObjectField;

/**
 * Further fields per space of a type of objects (decision 0250), e.g.
 * those an admin adds to a collection in the backend:
 *
 *     $registry->add(SpaceFields::class, new SpaceFields('frootbox/collections/entry', CollectionFields::class));
 *
 * The class is loaded from the container when a space's fields are read.
 */
final readonly class SpaceFields
{
    /**
     * @param class-string<SpaceFieldSource> $source
     */
    public function __construct(
        public string $type,
        public string $source,
    ) {}
}
