<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Design;

use Frootbox\Core\Action\InvalidInputException;
use Frootbox\Core\I18n\Labels;
use Frootbox\Core\Revision\Revisions;
use Frootbox\Core\Revision\RevisionStatus;
use Frootbox\Core\Revision\RevisionStore;
use Frootbox\Core\Support\Message;
use Frootbox\Core\Theme\Theme;
use Frootbox\Core\Theme\ThemeChain;
use Frootbox\Core\Theme\TokenDefinition;
use Frootbox\Core\Theme\TokenType;

/**
 * Changes the design of the active theme in its draft and publishes it
 * (decision 0127). Everything goes through RevisionStore, so the actions
 * stay undoable and the page cache clears itself.
 */
final readonly class DesignEditor
{
    public function __construct(
        private ThemeChain $themes,
        private Revisions $revisions,
        private RevisionStore $store,
        private Labels $labels,
    ) {}

    public function details(): DesignDetails
    {
        $theme = $this->themes->active();

        if (null === $theme) {
            return new DesignDetails(null, false, null, []);
        }

        $draft = $this->revisions->draft(DesignTokens::TYPE, $theme->name);
        $published = $this->revisions->published(DesignTokens::TYPE, $theme->name);
        $chosen = DesignTokens::values($draft ?? $published);
        $live = DesignTokens::values($published);
        $defaults = $this->themes->tokens();
        $definitions = $this->themes->definitions();
        $fonts = $this->themes->fonts();
        $value = static function (string $name, array $values) use ($definitions, $defaults, $fonts): string {
            $value = $values[$name] ?? null;

            return \is_string($value) && null === $definitions[$name]->problem($value, $fonts) ? $value : $defaults[$name] ?? '';
        };
        $tokens = [];

        foreach ($definitions as $name => $definition) {
            $current = $value($name, $chosen);
            $contrast = null;

            if (null !== $definition->contrast) {
                $other = isset($definitions[$definition->contrast]) ? $definitions[$definition->contrast]->css($value($definition->contrast, $chosen), $fonts) : ($defaults[$definition->contrast] ?? '');
                $ratio = Contrast::ratio($definition->css($current, $fonts), new TokenDefinition($definition->contrast, TokenType::Color, '')->css($other, $fonts));
                $contrast = ['token' => $definition->contrast, 'ratio' => $ratio, 'enough' => $ratio >= Contrast::ENOUGH];
            }

            $tokens[] = new TokenDetails(
                $name,
                $definition->type->value,
                $this->labels->text($definition->label, $theme->name),
                $current,
                $definition->css($current, $fonts),
                $value($name, $live),
                $defaults[$name],
                $this->options($definition, $fonts, $theme->name),
                $definition->min,
                $definition->max,
                $definition->step,
                $definition->unit,
                $contrast,
            );
        }

        return new DesignDetails($theme->name, null !== $draft, $published?->version, $tokens);
    }

    /**
     * Sets values in the draft; null goes back to the value of the theme.
     * A draft equal to the published design is removed.
     *
     * @param array<mixed> $tokens By name
     *
     * @throws InvalidInputException
     */
    public function update(string $action, array $tokens): DesignDetails
    {
        $theme = $this->theme($action);
        $definitions = $this->themes->definitions();
        $fonts = $this->themes->fonts();
        $published = DesignTokens::values($this->revisions->published(DesignTokens::TYPE, $theme->name));
        $draft = $this->revisions->draft(DesignTokens::TYPE, $theme->name);
        $values = null === $draft ? $published : DesignTokens::values($draft);
        $errors = [];

        foreach ($tokens as $name => $value) {
            $name = (string) $name;
            $definition = $definitions[$name] ?? null;

            if (null === $definition) {
                $errors['/tokens/' . $name] = new Message('The theme has no token "{name}" to change.', ['name' => $name]);

                continue;
            }

            if (null === $value) {
                unset($values[$name]);

                continue;
            }

            $value = \is_string($value) ? $definition->normalize($value) : '';
            $problem = $definition->problem($value, $fonts);

            if (null !== $problem) {
                $errors['/tokens/' . $name] = $problem;

                continue;
            }

            $values[$name] = $value;
        }

        if ([] !== $errors) {
            throw new InvalidInputException($action, $errors);
        }

        // What the themes no longer describe goes.
        $values = array_intersect_key($values, $definitions);
        ksort($values);
        ksort($published);

        if ($values === $published) {
            $this->store->discard(DesignTokens::TYPE, $theme->name);
        } else {
            $this->store->saveDraft(DesignTokens::TYPE, $theme->name, ['tokens' => $values]);
        }

        return $this->details();
    }

    /**
     * @throws InvalidInputException
     */
    public function publish(string $action): DesignDetails
    {
        $theme = $this->theme($action);

        if (null === $this->revisions->draft(DesignTokens::TYPE, $theme->name)) {
            throw new InvalidInputException($action, ['' => 'The design has no changes to publish.']);
        }

        $this->store->publish(DesignTokens::TYPE, $theme->name);

        return $this->details();
    }

    /**
     * @throws InvalidInputException
     */
    public function discard(string $action): DesignDetails
    {
        $theme = $this->theme($action);

        if (!$this->store->discard(DesignTokens::TYPE, $theme->name)) {
            throw new InvalidInputException($action, ['' => 'The design has no changes to discard.']);
        }

        return $this->details();
    }

    /**
     * Makes a new draft from a published version.
     *
     * @throws InvalidInputException
     */
    public function restore(string $action, int $version): DesignDetails
    {
        $theme = $this->theme($action);
        $old = $this->revisions->version(DesignTokens::TYPE, $theme->name, $version)
            ?? throw new InvalidInputException($action, ['/version' => new Message('The design has no version {version}.', ['version' => $version])]);

        // Values of tokens the theme no longer has are not taken over.
        $values = array_intersect_key(DesignTokens::values($old), $this->themes->definitions());
        $this->store->saveDraft(DesignTokens::TYPE, $theme->name, ['tokens' => $values]);

        return $this->details();
    }

    /**
     * @return list<DesignVersion>
     */
    public function versions(): array
    {
        $theme = $this->themes->active();

        if (null === $theme) {
            return [];
        }

        $versions = [];

        foreach ($this->revisions->versions(DesignTokens::TYPE, $theme->name) as $revision) {
            $versions[] = new DesignVersion(
                $revision->version ?? 0,
                RevisionStatus::Published === $revision->status,
                $revision->publishedAt ?? $revision->createdAt,
                $revision->authorId,
                $revision->channel,
            );
        }

        return $versions;
    }

    /**
     * @param array<string, \Frootbox\Core\Theme\Font> $fonts
     *
     * @return list<array{value: string, label: string, css: string}>
     */
    private function options(TokenDefinition $definition, array $fonts, string $package): array
    {
        return match ($definition->type) {
            TokenType::Font => array_values(array_map(fn($font): array => ['value' => $font->key, 'label' => $this->labels->text($font->label, $package), 'css' => $font->family], $fonts)),
            TokenType::Choice => array_map(fn(string $key, array $option): array => ['value' => $key, 'label' => $this->labels->text($option['label'], $package), 'css' => $option['value']], array_keys($definition->options), $definition->options),
            default => [],
        };
    }

    /**
     * @throws InvalidInputException
     */
    private function theme(string $action): Theme
    {
        return $this->themes->active() ?? throw new InvalidInputException($action, ['' => 'No theme is active, set one in config/app.yaml.']);
    }
}
