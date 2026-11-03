<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\ObjectField;

/**
 * Further fields an extension adds to a type of objects (decisions 0234,
 * 0240), written like "object.fields" in the header of a view:
 *
 *     $registry->add(ObjectFieldSet::class, new ObjectFieldSet('frootbox/jobs/job', [
 *         'downloads' => ['type' => 'list', 'label' => 'Downloads', 'fields' => ['file' => ['type' => 'file']]],
 *     ]));
 */
final readonly class ObjectFieldSet
{
    /**
     * @param array<string, mixed> $fields
     */
    public function __construct(
        public string $type,
        public array $fields,
        /** Shown in problems, e.g. the package */
        public string $from = 'an extension',
    ) {}
}
