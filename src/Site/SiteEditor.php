<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Site;

use Frootbox\Core\Action\ConflictException;
use Frootbox\Core\Action\InvalidInputException;
use Frootbox\Core\Action\Schema\InputValidator;
use Frootbox\Core\Component\ContentValidator;
use Frootbox\Core\Component\FieldSummary;
use Frootbox\Core\Field\Blank;
use Frootbox\Core\Field\EntryKey;
use Frootbox\Core\Field\Field;
use Frootbox\Core\Field\FieldTypes;
use Frootbox\Core\Field\Parts;
use Frootbox\Core\Language\Languages;
use Frootbox\Core\Media\MediaEditor;
use Frootbox\Core\Media\MediaReferences;
use Frootbox\Core\Media\UsageIndex;
use Frootbox\Core\Page\ContentField;
use Frootbox\Core\Revision\Revisions;
use Frootbox\Core\Revision\RevisionStatus;
use Frootbox\Core\Revision\RevisionStore;
use Frootbox\Core\Support\Message;
use Frootbox\Core\Theme\Theme;
use Frootbox\Core\Theme\ThemeChain;
use Frootbox\RichText\Html;
use Twig\Markup;

/**
 * Changes the fields of the website in their draft and publishes them
 * (decision 0128). Everything goes through RevisionStore, so the actions
 * stay undoable and the page cache clears itself.
 */
final readonly class SiteEditor
{
    public function __construct(
        private SiteFields $site,
        private ThemeChain $themes,
        private Revisions $revisions,
        private RevisionStore $store,
        private Languages $languages,
        private FieldTypes $types,
        private InputValidator $validator,
        private MediaReferences $references,
        private MediaEditor $media,
        private UsageIndex $usages,
        private SiteProtection $protection,
    ) {}

    public function details(): SiteDetails
    {
        $theme = $this->themes->active();

        if (null === $theme) {
            return new SiteDetails(null, false, null, [], [], $this->protection->status());
        }

        $draft = $this->revisions->draft(SiteFields::TYPE, $theme->name);
        $published = $this->revisions->published(SiteFields::TYPE, $theme->name);
        $stored = SiteFields::languages($draft ?? $published);
        $values = [];

        foreach ($this->languages->codes() as $code) {
            $values[$code] = $this->site->merged($stored, $code);
        }

        $own = [];

        foreach ($this->languages->codes() as $code) {
            $own[$code] = $stored[$code] ?? [];
        }

        return new SiteDetails($theme->name, null !== $draft, $published?->version, FieldSummary::all($this->site->translated()), $values, $this->protection->status(), $own);
    }

    /**
     * The published versions, the newest first (decision 0348).
     *
     * @return list<SiteVersion>
     */
    public function versions(): array
    {
        $theme = $this->themes->active();

        if (null === $theme) {
            return [];
        }

        $versions = [];

        foreach ($this->revisions->versions(SiteFields::TYPE, $theme->name) as $revision) {
            $versions[] = new SiteVersion(
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
     * Makes a new draft from an earlier version; values of fields the
     * theme no longer has are left out (decision 0348).
     *
     * @throws InvalidInputException
     */
    public function restore(string $action, int $version): SiteDetails
    {
        $theme = $this->theme($action);
        $old = $this->revisions->version(SiteFields::TYPE, $theme->name, $version);

        if (null === $old || null === $old->version) {
            throw new InvalidInputException($action, ['/version' => new Message('The fields of the website have no version {version}.', ['version' => $version])]);
        }

        $fields = $this->site->fields();
        $languages = [];

        foreach (SiteFields::languages($old) as $code => $values) {
            if ($this->languages->has($code)) {
                $languages[$code] = array_intersect_key($values, $fields);
            }
        }

        $languages = array_filter($languages, static fn(array $values): bool => [] !== $values);
        $published = SiteFields::languages($this->revisions->published(SiteFields::TYPE, $theme->name));

        if (ContentField::same($languages, $published)) {
            $this->store->discard(SiteFields::TYPE, $theme->name);
        } else {
            $this->store->saveDraft(SiteFields::TYPE, $theme->name, ['languages' => $languages]);
        }

        $this->usages->site();

        return $this->details();
    }

    /**
     * Sets fields of one language in the draft; null removes a value, so
     * the one of the first language shows.
     *
     * @param array<mixed> $fields By name
     *
     * @throws InvalidInputException
     */
    public function update(string $action, string $language, array $fields): SiteDetails
    {
        $theme = $this->theme($action);
        $this->language($action, $language);
        $languages = $this->current($theme);
        $own = $languages[$language] ?? [];

        foreach ($fields as $name => $value) {
            // Empty is no value of its own, e.g. a field of a form left empty (decision 0348).
            $own = self::named(self::put($own, [(string) $name], self::empty($value) ? null : $value));
        }

        $languages[$language] = $own;
        $this->save($action, $theme, $languages, $own, '/fields');

        return $this->details();
    }

    /**
     * Changes one field of one language, e.g. while editing on the
     * website; fails if it no longer holds $previous.
     *
     * @throws InvalidInputException
     * @throws ConflictException
     */
    public function updateField(string $action, string $language, string $path, mixed $value, mixed $previous): SiteFieldUpdated
    {
        $theme = $this->theme($action);
        $this->language($action, $language);
        $keys = [];
        $languages = $this->current($theme);
        $merged = $this->site->merged($languages, $language);
        $field = $this->locate($action, $path, $keys, $merged);

        if (!ContentField::same(self::at($merged, $keys), $previous)) {
            throw ConflictException::field();
        }

        // An entry of a list of the first language becomes this language's own list.
        $own = $languages[$language] ?? [];
        $name = (string) $keys[0];

        if (\count($keys) > 1 && !\array_key_exists($name, $own)) {
            $own[$name] = $merged[$name] ?? null;
        }

        $own = self::named(self::put($own, $keys, $value));
        $languages[$language] = $own;
        $this->save($action, $theme, $languages, $own, '/value');

        $saved = self::at($this->site->merged($this->current($theme), $language), $keys);
        $type = $this->types->get($field->type);
        $output = $type->output($saved, $field);

        return new SiteFieldUpdated(
            $language,
            $path,
            $saved,
            $output instanceof Markup ? (string) $output : (\is_string($output) ? Html::escape($output) : ''),
            null,
            Parts::html($type, $saved, $field),
        );
    }

    /**
     * Checks that every language is complete and puts the draft online.
     *
     * @throws InvalidInputException
     */
    public function publish(string $action): SiteDetails
    {
        $theme = $this->theme($action);
        $draft = $this->revisions->draft(SiteFields::TYPE, $theme->name)
            ?? throw new InvalidInputException($action, ['' => 'The fields of the website have no changes to publish.']);
        $fields = $this->site->fields();
        $stored = SiteFields::languages($draft);
        $errors = [];

        foreach ($this->languages->codes() as $code) {
            $values = $this->site->merged($stored, $code);

            foreach ($this->validator->messages($this->types->schemaForPublishing($fields, $values), $values) + $this->types->validate($fields, $values) as $pointer => $message) {
                $errors['/languages/' . $code . ('/' === $pointer ? '' : $pointer)] = $message;
            }
        }

        if ([] !== $errors) {
            throw new InvalidInputException($action, $errors);
        }

        $this->store->publish(SiteFields::TYPE, $theme->name);
        $this->usages->site();

        // Media become public with the published fields, as with pages (decision 0126).
        $this->media->makePublic(MediaReferences::merge($this->references->inFieldsByLanguage($fields, $stored)));

        return $this->details();
    }

    /**
     * @throws InvalidInputException
     */
    public function discard(string $action): SiteDetails
    {
        $theme = $this->theme($action);

        if (!$this->store->discard(SiteFields::TYPE, $theme->name)) {
            throw new InvalidInputException($action, ['' => 'The fields of the website have no changes to discard.']);
        }

        $this->usages->site();

        return $this->details();
    }

    /**
     * The values of the draft or, without one, the published ones.
     *
     * @return array<string, array<string, mixed>>
     */
    private function current(Theme $theme): array
    {
        return SiteFields::languages($this->revisions->draft(SiteFields::TYPE, $theme->name) ?? $this->revisions->published(SiteFields::TYPE, $theme->name));
    }

    /**
     * Checks the changed language as a draft and saves; a draft equal to
     * the published values is removed.
     *
     * @param array<string, array<string, mixed>> $languages
     * @param array<string, mixed>                $own       The values of the changed language
     *
     * @throws InvalidInputException
     */
    private function save(string $action, Theme $theme, array $languages, array $own, string $pointer): void
    {
        $fields = $this->site->fields();
        // Ids for the entries of lists (decision 0344).
        $languages = array_map(fn(array $values): array => $this->types->normalize($fields, $values), $languages);
        $own = $this->types->normalize($fields, $own);
        $found = $this->validator->messages(ContentValidator::withoutMinimums($this->types->schemaFor($fields)), $own) + $this->types->validate($fields, $own);

        if ([] !== $found) {
            $errors = [];

            foreach ($found as $path => $message) {
                $errors[$pointer . ('/' === $path ? '' : ('/value' === $pointer ? '' : $path))] = $message;
            }

            throw new InvalidInputException($action, $errors);
        }

        $languages = array_filter($languages, static fn(array $values): bool => [] !== $values);
        $published = SiteFields::languages($this->revisions->published(SiteFields::TYPE, $theme->name));

        if (ContentField::same($languages, $published)) {
            $this->store->discard(SiteFields::TYPE, $theme->name);
        } else {
            $this->store->saveDraft(SiteFields::TYPE, $theme->name, ['languages' => $languages]);
        }

        $this->usages->site();
    }

    /**
     * Finds the field at a path such as "address" or "links/0/text", an
     * entry also by its id: "links/k3f9x2ab/text" (decision 0344).
     *
     * @param list<string|int> $keys   The way to its value
     * @param array<mixed>     $values The values to find entries in
     *
     * @throws InvalidInputException
     */
    private function locate(string $action, string $path, array &$keys, array $values): Field
    {
        $segments = explode('/', $path);
        $fields = $this->site->fields();
        $data = $values;

        while (true) {
            $name = array_shift($segments);
            $field = $fields[$name] ?? throw new InvalidInputException($action, ['/path' => new Message('The website has no field "{name}".', ['name' => $name])]);
            $keys[] = $name;

            if ([] === $segments) {
                return $field;
            }

            $segment = array_shift($segments);

            if ([] === $field->fields || 1 !== preg_match('/^' . EntryKey::SEGMENT . '$/D', $segment) || [] === $segments) {
                throw new InvalidInputException($action, ['/path' => new Message('"{name}" is no list with entries to change; a path looks like "links/0/text".', ['name' => $name])]);
            }

            $entries = \is_array($data) ? $data[$name] ?? null : null;
            $index = EntryKey::position($entries, $segment);

            if (null === $index) {
                // A position past the end stays as before; an id must name an entry.
                if (EntryKey::isId($segment)) {
                    throw new InvalidInputException($action, ['/path' => new Message('The list has no entry {index}.', ['index' => $segment])]);
                }

                $index = (int) $segment;
            }

            $keys[] = $index;
            $data = \is_array($entries) ? $entries[$index] ?? null : null;
            $fields = $field->fields;
        }
    }

    /**
     * @param array<mixed>     $data
     * @param list<string|int> $keys
     */
    private static function at(array $data, array $keys): mixed
    {
        $value = $data;

        foreach ($keys as $key) {
            if (!\is_array($value) || !\array_key_exists($key, $value)) {
                return null;
            }

            $value = $value[$key];
        }

        return $value;
    }

    /**
     * The data with the new value; null removes it.
     *
     * @param array<mixed>     $data
     * @param list<string|int> $keys
     *
     * @return array<mixed>
     */
    private static function put(array $data, array $keys, mixed $value): array
    {
        $key = array_shift($keys);

        if (null === $key) {
            return $data;
        }

        if ([] === $keys) {
            if (null === $value) {
                unset($data[$key]);
            } else {
                $data[$key] = $value;
            }

            return $data;
        }

        $data[$key] = self::put(\is_array($data[$key] ?? null) ? $data[$key] : [], $keys, $value);

        return $data;
    }

    /**
     * @param array<mixed> $data
     *
     * @return array<string, mixed>
     */
    private static function named(array $data): array
    {
        $named = [];

        foreach ($data as $key => $value) {
            $named[(string) $key] = $value;
        }

        return $named;
    }

    /**
     * Whether a value holds nothing an editor entered, the kinds of nodes
     * of rich text not counted: {"type": "doc", "content": [{"type": "paragraph"}]}.
     */
    private static function empty(mixed $value): bool
    {
        if (!\is_array($value)) {
            return Blank::is($value);
        }

        unset($value['type']);

        return [] === array_filter($value, static fn(mixed $item): bool => !self::empty($item));
    }

    /**
     * @throws InvalidInputException
     */
    private function language(string $action, string $language): void
    {
        if (!$this->languages->has($language)) {
            throw new InvalidInputException($action, ['/language' => new Message('The website has no language "{language}". Languages: {languages}.', ['language' => $language, 'languages' => implode(', ', $this->languages->codes())])]);
        }
    }

    /**
     * @throws InvalidInputException
     */
    private function theme(string $action): Theme
    {
        return $this->themes->active() ?? throw new InvalidInputException($action, ['' => 'No theme is active, set one in config/app.yaml.']);
    }
}
