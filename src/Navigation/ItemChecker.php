<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Navigation;

use Frootbox\Core\Page\PageReader;
use Frootbox\Core\Support\Message;
use Frootbox\Core\Url\Target;
use Frootbox\RichText\Attribute;

/**
 * Checks the items of a navigation in one language and gives each one an
 * id (decision 0106):
 *
 *     [{"type": "target", "target": "page:01J…", "children": [
 *         {"type": "anchor", "anchor": "team", "target": "page:01J…", "label": "Team"}]},
 *      {"type": "phone", "phone": "+49 40 123456"},
 *      {"type": "pages", "target": "page:01J…", "depth": 2}]
 */
final readonly class ItemChecker
{
    public const array TYPES = ['target', 'url', 'anchor', 'phone', 'email', 'heading', 'pages'];

    public const string ID_PATTERN = '^[a-z0-9]{8}$';

    private const string ID_CHARACTERS = 'abcdefghijklmnopqrstuvwxyz0123456789';

    private const array COMMON = ['id', 'type', 'label', 'newWindow', 'options', 'children'];

    /** The fields of each type besides the common ones, required ones first. */
    private const array FIELDS = [
        'target' => ['target'],
        'url' => ['url'],
        'anchor' => ['anchor', 'target'],
        'phone' => ['phone'],
        'email' => ['email'],
        'heading' => [],
        'pages' => ['target', 'depth'],
    ];

    /** Types that need a label, as nothing else names them. */
    private const array LABELLED = ['url', 'anchor', 'heading'];

    private const int MAX_LABEL = 200;

    private const int MAX_PAGES_DEPTH = 5;

    public function __construct(private PageReader $reader) {}

    /**
     * @param mixed  $items   The items of one language
     * @param string $pointer JSON pointer to them, e.g. "/items/de"
     *
     * @return array{list<array<string, mixed>>, array<string, string|Message>} The items, and messages by JSON pointer
     */
    public function check(mixed $items, Definition $definition, string $pointer): array
    {
        $ids = [];
        $errors = [];
        $pages = array_keys($this->reader->nodes());

        return [$this->level($items, $definition, $pointer, 1, array_flip($pages), $ids, $errors), $errors];
    }

    /**
     * @param array<string, int>    $pages Ids of existing pages
     * @param array<string, true>   $ids
     * @param array<string, string|Message> $errors
     *
     * @return list<array<string, mixed>>
     */
    private function level(mixed $items, Definition $definition, string $pointer, int $depth, array $pages, array &$ids, array &$errors): array
    {
        if (!\is_array($items) || !array_is_list($items)) {
            $errors[$pointer] = 'must be a list of items.';

            return [];
        }

        if ($depth > $definition->depth && [] !== $items) {
            $errors[$pointer] = new Message('The navigation "{name}" has {depth} level(s), these items are deeper.', ['name' => $definition->name, 'depth' => $definition->depth]);

            return [];
        }

        $checked = [];

        foreach ($items as $index => $item) {
            $at = $pointer . '/' . $index;

            if (!\is_array($item) || ([] !== $item && array_is_list($item))) {
                $errors[$at] = 'must be an object with "type".';

                continue;
            }

            $item = $this->item($item, $definition, $at, $depth, $pages, $ids, $errors);

            if (null !== $item) {
                $checked[] = $item;
            }
        }

        return $checked;
    }

    /**
     * @param array<mixed>          $item
     * @param array<string, int>    $pages
     * @param array<string, true>   $ids
     * @param array<string, string|Message> $errors
     *
     * @return array<string, mixed>|null
     */
    private function item(array $item, Definition $definition, string $at, int $depth, array $pages, array &$ids, array &$errors): ?array
    {
        $type = $item['type'] ?? null;

        if (!\is_string($type) || !isset(self::FIELDS[$type])) {
            $errors[$at . '/type'] = \sprintf('must be one of %s.', implode(', ', self::TYPES));

            return null;
        }

        $allowed = [...self::COMMON, ...self::FIELDS[$type]];
        $unknown = array_diff(array_map(strval(...), array_keys($item)), $allowed);

        if ([] !== $unknown) {
            $errors[$at] = \sprintf('Unknown keys for "%s": %s. Allowed: %s.', $type, implode(', ', $unknown), implode(', ', $allowed));
        }

        $checked = ['id' => $this->id($item['id'] ?? null, $at . '/id', $ids, $errors), 'type' => $type];
        $label = $item['label'] ?? null;

        if (null !== $label || \in_array($type, self::LABELLED, true)) {
            if (!\is_string($label) || '' === trim($label) || mb_strlen($label) > self::MAX_LABEL) {
                $errors[$at . '/label'] = \in_array($type, self::LABELLED, true)
                    ? new Message('must be a text of 1 to {max} characters, it names the item.', ['max' => self::MAX_LABEL])
                    : new Message('must be a text of 1 to {max} characters.', ['max' => self::MAX_LABEL]);
            } else {
                $checked['label'] = trim($label);
            }
        }

        foreach (self::FIELDS[$type] as $position => $field) {
            $value = $item[$field] ?? null;
            $required = 0 === $position;

            if (null === $value && !$required) {
                continue;
            }

            $error = $this->field($field, $value, $pages);

            if (null === $error) {
                $checked[$field] = \is_string($value) ? trim($value) : $value;
            } else {
                $errors[$at . '/' . $field] = $error;
            }
        }

        if (isset($item['newWindow'])) {
            \is_bool($item['newWindow']) ? $checked['newWindow'] = $item['newWindow'] : $errors[$at . '/newWindow'] = 'must be true or false.';
        }

        $options = $this->options($item['options'] ?? [], $definition, $at . '/options', $errors);

        if ([] !== $options) {
            $checked['options'] = $options;
        }

        if ('pages' === $type) {
            if (isset($item['children'])) {
                $errors[$at . '/children'] = 'are the subpages of the page; "pages" has no own children.';
            }
        } elseif (isset($item['children'])) {
            $checked['children'] = $this->level($item['children'], $definition, $at . '/children', $depth + 1, $pages, $ids, $errors);
        }

        return $checked;
    }

    /**
     * @param array<string, int> $pages
     */
    private function field(string $field, mixed $value, array $pages): string|Message|null
    {
        return match ($field) {
            'target' => self::checkTarget($value, $pages),
            'url' => Attribute::url(required: true)->clean($value)[1],
            'anchor' => \is_string($value) && 1 === preg_match('/^[A-Za-z][A-Za-z0-9_-]{0,99}$/', $value) ? null : 'must be the id of an element without "#", e.g. "contact".',
            'phone' => \is_string($value) && 1 === preg_match('/^\+?[0-9][0-9 ()\/.-]{2,29}$/', $value) ? null : 'must be a phone number such as "+49 40 123456".',
            'email' => \is_string($value) && false !== filter_var($value, \FILTER_VALIDATE_EMAIL) ? null : 'must be an e-mail address.',
            default => \is_int($value) && $value >= 1 && $value <= self::MAX_PAGES_DEPTH ? null : new Message('must be a whole number from 1 to {max}.', ['max' => self::MAX_PAGES_DEPTH]),
        };
    }

    /**
     * @param array<string, int> $pages
     */
    private static function checkTarget(mixed $value, array $pages): string|Message|null
    {
        $target = \is_string($value) ? Target::tryParse($value) : null;

        if (null === $target) {
            return 'must be a target such as "page:01J…".';
        }

        if (!$target->isPage()) {
            return 'Only pages can be linked so far.';
        }

        return isset($pages[$target->id]) ? null : new Message('There is no page {id}.', ['id' => $target->id]);
    }

    /**
     * @param array<string, string|Message> $errors
     *
     * @return array<string, mixed>
     */
    private function options(mixed $options, Definition $definition, string $at, array &$errors): array
    {
        if (!\is_array($options) || ([] !== $options && array_is_list($options))) {
            $errors[$at] = 'must be an object of options.';

            return [];
        }

        $checked = [];

        foreach ($options as $name => $value) {
            $option = $definition->options[(string) $name] ?? null;

            if (null === $option) {
                $errors[$at . '/' . $name] = \sprintf('The navigation "%s" has no option "%s". Options: %s.', $definition->name, $name, [] === $definition->options ? 'none' : implode(', ', array_keys($definition->options)));
            } elseif (!$option->accepts($value)) {
                $errors[$at . '/' . $name] = \sprintf('is not a valid value for "%s".', $option->label);
            } else {
                $checked[(string) $name] = $value;
            }
        }

        return $checked;
    }

    /**
     * @param array<string, true>   $ids
     * @param array<string, string|Message> $errors
     */
    private function id(mixed $id, string $pointer, array &$ids, array &$errors): string
    {
        if (\is_string($id) && 1 === preg_match('/' . self::ID_PATTERN . '/', $id) && !isset($ids[$id])) {
            $ids[$id] = true;

            return $id;
        }

        if (null !== $id) {
            $errors[$pointer] = 'must be 8 characters a-z and 0-9, unique in the navigation, or be left out for a new item.';
        }

        do {
            $new = '';

            for ($i = 0; $i < 8; ++$i) {
                $new .= self::ID_CHARACTERS[random_int(0, 35)];
            }
        } while (isset($ids[$new]));

        $ids[$new] = true;

        return $new;
    }
}
