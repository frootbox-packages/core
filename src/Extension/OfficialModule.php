<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Extension;

/**
 * An official module of Frootbox from the catalog the core brings
 * (decision 0279), in English as its manifest names it.
 */
final readonly class OfficialModule implements \JsonSerializable
{
    public string $command;

    public function __construct(
        public string $name,
        public string $title,
        public string $description,
    ) {
        $this->command = 'composer require ' . $name;
    }

    /**
     * @return array{name: string, title: string, description: string}
     */
    public function jsonSerialize(): array
    {
        return ['name' => $this->name, 'title' => $this->title, 'description' => $this->description];
    }
}
