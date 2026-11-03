<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Tag;

use Frootbox\Core\Action\AsQuery;
use Frootbox\Core\Action\Query;
use Frootbox\Core\Action\Schema\Description;

/**
 * @implements Query<array{closed: bool, options: list<array{value: string, label: string, internal: bool, preset: bool, checked: bool}>, known: list<string>}>
 */
#[AsQuery('tag.choices', 'What a form offers for the tags of an object: the chosen ones, the presets and the most used ones; closed when only presets count', signedIn: true)]
final readonly class ChooseTags implements Query
{
    /**
     * @param list<string> $chosen
     */
    public function __construct(
        #[Description('The type of the object, e.g. "frootbox/jobs/job"')]
        public string $type,
        #[Description('The space of the object, left out for the default one')]
        public ?string $space = null,
        #[Description('Ids of the tags it has')]
        public array $chosen = [],
    ) {}

    /**
     * @return array{closed: bool, options: list<array{value: string, label: string, internal: bool, preset: bool, checked: bool}>, known: list<string>}
     */
    public function handle(ObjectTags $tags): array
    {
        return $tags->choices($this->type, $this->space, array_values(array_filter($this->chosen, \is_string(...))));
    }
}
