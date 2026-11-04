<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Extension\Check;

/**
 * One error or hint of extension:check (decision 0278).
 *
 * $rule names the check, e.g. "composer", "load", "table" or
 * "component"; $where the place, e.g. "composer.json" or a block name,
 * empty when the message names it.
 */
final readonly class CheckFinding implements \JsonSerializable
{
    public function __construct(
        public string $rule,
        public string $where,
        public string $message,
    ) {}

    public function __toString(): string
    {
        return ('' === $this->where ? '' : $this->where . ': ') . $this->message . ' [' . $this->rule . ']';
    }

    /**
     * @return array{rule: string, where: string, message: string}
     */
    public function jsonSerialize(): array
    {
        return ['rule' => $this->rule, 'where' => $this->where, 'message' => $this->message];
    }
}
