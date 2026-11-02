<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Component;

use Frootbox\Core\Action\ActionException;
use Frootbox\Core\Action\Runner;

/**
 * Fills the choices of options with a source from its query, e.g. the
 * forms to choose in the block "Form" (decision 0222). A query the user
 * may not run gives no choices.
 */
final class OptionChoices
{
    /** @var array<string, array<int|string, string>> by query */
    private array $choices = [];

    public function __construct(private readonly Runner $runner) {}

    public function fill(Component $component): Component
    {
        $sourced = false;

        foreach ($component->variants as $variant) {
            foreach ($variant->options as $option) {
                $sourced = $sourced || null !== $option->source;
            }
        }

        if (!$sourced) {
            return $component;
        }

        return $component->withVariants(array_map(fn(Variant $variant): Variant => new Variant(
            $variant->name,
            $variant->title,
            $variant->description,
            array_map(fn(Option $option): Option => null === $option->source ? $option : $option->withChoices($this->choices($option->source)), $variant->options),
            $variant->template,
            $variant->from,
        ), $component->variants));
    }

    /**
     * @return array<int|string, string> value => label
     */
    private function choices(string $query): array
    {
        if (isset($this->choices[$query])) {
            return $this->choices[$query];
        }

        try {
            $result = $this->runner->call($query, []);
        } catch (ActionException) {
            $result = [];
        }

        $choices = [];

        foreach (\is_array($result) ? $result : [] as $choice) {
            $value = \is_array($choice) ? ($choice['value'] ?? null) : (\is_object($choice) ? ($choice->value ?? null) : null);
            $label = \is_array($choice) ? ($choice['label'] ?? null) : (\is_object($choice) ? ($choice->label ?? null) : null);

            if ((\is_string($value) || \is_int($value)) && \is_string($label)) {
                $choices[$value] = $label;
            }
        }

        return $this->choices[$query] = $choices;
    }
}
