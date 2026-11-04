<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Reference;

use Frootbox\Core\Action\ActionExecuted;
use Frootbox\Core\Action\Descriptor;
use Frootbox\Core\Console\CommandInfo;
use Frootbox\Core\Extension\Mount;
use Frootbox\Core\Extension\Registry;
use Frootbox\Core\Field\AsFieldType;
use Frootbox\Core\Schedule\ScheduleRan;
use Frootbox\Core\View\BuildingHead;
use Frootbox\Core\View\ViewExtension;
use Psr\Container\ContainerInterface;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;
use Symfony\Component\Console\Command\Command;
use Throwable;

/**
 * The reference for developers, written from the code of the installed
 * packages: actions and queries, console commands, extension points of
 * the registry, Twig functions and field types (decision 0265). Nothing
 * in it is written by hand, "reference:build" writes it anew.
 */
final readonly class Reference
{
    public const string MARKER = '<!-- Written by bin/frootbox reference:build from the code. Do not edit. -->';

    /** The events the core dispatches; a test keeps the list complete. */
    public const array EVENTS = [ActionExecuted::class, BuildingHead::class, ScheduleRan::class];

    public function __construct(
        private Registry $registry,
        private ContainerInterface $container,
    ) {}

    /**
     * @return array<string, string> file name => Markdown
     */
    public function pages(): array
    {
        return [
            'README.md' => $this->index(),
            'actions.md' => $this->actions(),
            'console.md' => $this->console(),
            'registry.md' => $this->extensionPoints(),
            'twig.md' => $this->twig(),
            'field-types.md' => $this->fieldTypes(),
            'block-schema.md' => self::page('Block schema', BlockSchema::text()),
            'events.md' => self::events(),
        ];
    }

    /**
     * Writes the pages into the folder, removes pages written before that
     * are gone, and returns the files that changed. With $check nothing
     * is written, the list tells whether the folder is behind.
     *
     * @param array<string, string> $pages
     *
     * @return list<string>
     */
    public static function sync(string $folder, array $pages, bool $check = false): array
    {
        $changed = [];

        foreach ($pages as $file => $markdown) {
            $path = $folder . '/' . $file;

            if (!is_file($path) || file_get_contents($path) !== $markdown) {
                $changed[] = $file;
            }
        }

        $old = glob($folder . '/*.md');

        foreach (false === $old ? [] : $old as $path) {
            if (!isset($pages[basename($path)]) && str_starts_with((string) file_get_contents($path), self::MARKER)) {
                $changed[] = basename($path);

                if (!$check) {
                    unlink($path);
                }
            }
        }

        if ($check) {
            return $changed;
        }

        if (!is_dir($folder) && !mkdir($folder, 0o777, true) && !is_dir($folder)) {
            throw new \RuntimeException(\sprintf('Could not create %s.', $folder));
        }

        foreach ($pages as $file => $markdown) {
            if (\in_array($file, $changed, true)) {
                file_put_contents($folder . '/' . $file, $markdown);
            }
        }

        return $changed;
    }

    /**
     * The first paragraphs of a doc comment without tags and without the
     * numbers of the internal decisions, which a reader outside cannot open.
     */
    public static function summary(string|false $comment): string
    {
        if (false === $comment) {
            return '';
        }

        $lines = [];

        foreach (explode("\n", $comment) as $line) {
            $line = trim($line);
            $line = trim(preg_replace('#^(/\*\*|\*/|\*)#', '', $line) ?? '');

            if (str_starts_with($line, '@')) {
                break;
            }

            $lines[] = $line;
        }

        $text = trim(preg_replace('/\s+/', ' ', implode(' ', $lines)) ?? '');

        return self::withoutDecisions($text);
    }

    /**
     * The whole text of a doc comment as Markdown: paragraphs stay,
     * lines indented by four spaces are code, markup and Twig in the
     * text become code spans.
     */
    public static function details(string|false $comment): string
    {
        if (false === $comment) {
            return '';
        }

        $blocks = [];
        $block = [];
        $code = false;

        foreach (explode("\n", $comment) as $line) {
            $line = rtrim(preg_replace('#^\s*(/\*\*|\*/|\* ?)#', '', $line) ?? '');

            if (str_starts_with($line, '@')) {
                break;
            }

            $isCode = str_starts_with($line, '    ');

            if ('' === $line || $isCode !== $code) {
                if ([] !== $block) {
                    $blocks[] = $code ? "```\n" . implode("\n", $block) . "\n```" : self::prose(implode(' ', $block));
                }

                $block = [];
                $code = $isCode;

                if ('' === $line) {
                    continue;
                }
            }

            $block[] = $isCode ? substr($line, 4) : trim($line);
        }

        if ([] !== $block) {
            $blocks[] = $code ? "```\n" . implode("\n", $block) . "\n```" : self::prose(implode(' ', $block));
        }

        return implode("\n\n", $blocks);
    }

    private static function prose(string $text): string
    {
        $text = self::withoutDecisions($text);
        $text = preg_replace_callback('/(?:<[^>]+>|\{\{.*?\}\})(?:\s*(?:<[^>]+>|\{\{.*?\}\}))*/', static fn(array $match): string => '`' . $match[0] . '`', $text) ?? $text;

        return $text;
    }

    public static function withoutDecisions(string $text): string
    {
        $numbers = 'decisions? \d{4}(?:(?:, | and | to |–)\d{4})*';
        $text = preg_replace('/\s*\((?:see )?' . $numbers . '\)/', '', $text) ?? $text;
        $text = preg_replace('/[,;] (?:see )?' . $numbers . '(?=\))/', '', $text) ?? $text;
        $text = preg_replace('/\s*\(' . $numbers . '[,;] /', ' (', $text) ?? $text;

        return trim(preg_replace('/ {2,}/', ' ', $text) ?? $text);
    }

    private function index(): string
    {
        return self::page('Reference', <<<'MD'
            Written from the code of the installed packages, so it is always
            the one of this version.

            - [Actions and queries](actions.md): every change and every read,
              with its input and its right. The same for the backend, the
              REST API (`POST /api/v1/<action>`, `GET /api/v1/<query>`), the
              console and the AI.
            - [Console commands](console.md) of `bin/frootbox`.
            - [Extension points](registry.md): what an extension registers in
              `register(Registry $registry)`.
            - [Twig functions](twig.md) for blocks, layouts and partials.
            - [Field types](field-types.md) for the fields of blocks, the
              website and objects.
            - [Block schema](block-schema.md): the keys of `block.yaml` and
              of the header of a view, for blocks and layouts.
            - [Events](events.md) an extension can listen to.
            MD);
    }

    private function actions(): string
    {
        $groups = [];

        foreach ($this->registry->catalog()->all() as $descriptor) {
            $groups[self::package($descriptor->name)][] = $descriptor;
        }

        uksort($groups, static fn(string $a, string $b): int => ['frootbox/core' !== $a, $a] <=> ['frootbox/core' !== $b, $b]);
        $text = "Actions change data, queries read it. Every one has a right, which\n"
            . "a role grants by its name or a pattern such as `page.*`. Public ones need\n"
            . "no sign-in, those for the own account only one. Actions can be undone\n"
            . "with `action.undo` unless they say otherwise.\n";

        foreach ($groups as $package => $descriptors) {
            $text .= "\n## " . $package . "\n";

            foreach ($descriptors as $descriptor) {
                $text .= "\n### `" . $descriptor->name . "`\n\n" . self::withoutDecisions($descriptor->description) . "\n\n" . self::access($descriptor) . "\n";
                $text .= self::input($descriptor);
            }
        }

        return self::page('Actions and queries', $text);
    }

    private static function access(Descriptor $descriptor): string
    {
        $parts = [$descriptor->isAction() ? 'Action' : 'Query'];
        $parts[] = match (true) {
            $descriptor->public => 'public',
            $descriptor->signedIn => 'for the own account',
            $descriptor->preview => 'also with a preview link, right `' . $descriptor->permission . '`',
            default => 'right `' . $descriptor->permission . '`',
        };

        if ($descriptor->isAction() && !$descriptor->undoable) {
            $parts[] = 'cannot be undone';
        }

        return implode(' · ', $parts);
    }

    private static function input(Descriptor $descriptor): string
    {
        $schema = $descriptor->schema();
        $properties = \is_array($schema['properties'] ?? null) ? $schema['properties'] : [];

        if ([] === $properties) {
            return '';
        }

        $required = \is_array($schema['required'] ?? null) ? $schema['required'] : [];
        $text = "\n| Input | Type | Description |\n|---|---|---|\n";

        foreach ($properties as $name => $property) {
            $property = \is_array($property) ? $property : [];
            $type = self::type($property) . (\in_array($name, $required, true) ? '' : ', optional');
            $description = \is_string($property['description'] ?? null) ? self::withoutDecisions($property['description']) : '';
            $text .= '| `' . $name . '` | ' . self::cell($type) . ' | ' . self::cell($description) . " |\n";
        }

        return $text;
    }

    /**
     * @param array<mixed> $property
     */
    private static function type(array $property): string
    {
        $types = $property['type'] ?? 'mixed';
        $types = \is_array($types) ? array_values(array_filter($types, \is_string(...))) : [\is_string($types) ? $types : 'mixed'];

        if (\in_array('array', $types, true) && \in_array('object', $types, true)) {
            $types = [...array_diff($types, ['array', 'object']), 'object'];
        }

        $type = implode(' or ', $types);

        if (\is_array($property['enum'] ?? null)) {
            $values = array_map(static fn(mixed $value): string => '`' . (\is_scalar($value) ? (string) $value : 'null') . '`', array_values(array_filter($property['enum'], static fn(mixed $value): bool => null !== $value)));
            $type .= ': ' . implode(', ', $values);
        }

        if (\is_string($property['format'] ?? null)) {
            $type .= ' (' . $property['format'] . ')';
        }

        return $type;
    }

    private function console(): string
    {
        $commands = $this->registry->commands();
        ksort($commands);
        $text = "Run them with `php bin/frootbox <command>`, `php bin/frootbox help <command>`\n"
            . "shows the same. The Doctrine commands `migrations:*` are there as well,\n"
            . "for details such as status or rollback.\n";

        foreach ($commands as $name => $class) {
            $info = CommandInfo::of($class);

            if ($info->hidden) {
                continue;
            }

            $text .= "\n## `" . $name . "`\n\n" . self::withoutDecisions($info->description) . "\n";

            try {
                $command = $this->container->get($class);
            } catch (Throwable) {
                continue;
            }

            if (!$command instanceof Command) {
                continue;
            }

            $text .= "\n```\nphp bin/frootbox " . $command->getSynopsis(true) . "\n```\n";
            $definition = $command->getDefinition();
            $rows = '';

            foreach ($definition->getArguments() as $argument) {
                $rows .= '| `' . $argument->getName() . '` | ' . self::cell(self::withoutDecisions($argument->getDescription())) . " |\n";
            }

            foreach ($definition->getOptions() as $option) {
                $rows .= '| `--' . $option->getName() . '` | ' . self::cell(self::withoutDecisions($option->getDescription())) . " |\n";
            }

            if ('' !== $rows) {
                $text .= "\n| Argument or option | Description |\n|---|---|\n" . $rows;
            }
        }

        return self::page('Console commands', $text);
    }

    private function extensionPoints(): string
    {
        $text = "An extension names a class in `extension:` of its `frootbox.yaml`. Its\n"
            . "method `register(Registry \$registry)` registers what the package brings.\n"
            . "Migrations in `migrations/` are found without it.\n";

        foreach (new ReflectionClass(Registry::class)->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            $return = $method->getReturnType();
            $returns = $return instanceof ReflectionNamedType ? $return->getName() : '';

            if ($method->isStatic() || $method->isConstructor() || !\in_array($returns, ['void', Mount::class], true) || str_contains((string) $method->getDocComment(), '@internal')) {
                continue;
            }

            $parameters = implode(', ', array_map(self::parameter(...), $method->getParameters()));
            $text .= "\n## `" . $method->getName() . '(' . $parameters . ')`' . "\n";
            $summary = self::summary($method->getDocComment());
            $text .= '' === $summary ? '' : "\n" . $summary . "\n";
        }

        return self::page('Extension points', $text);
    }

    private static function parameter(ReflectionParameter $parameter): string
    {
        $type = $parameter->getType();
        $text = (null === $type ? '' : str_replace(['Frootbox\Core\Extension\\'], '', (string) $type) . ' ') . ($parameter->isVariadic() ? '...' : '') . '$' . $parameter->getName();

        if ($parameter->isDefaultValueAvailable()) {
            $default = $parameter->getDefaultValue();
            $text .= ' = ' . (null === $default ? 'null' : var_export($default, true));
        }

        return $text;
    }

    /**
     * From the list in the doc comment of the Twig extension, one line per
     * function with its description below.
     */
    private function twig(): string
    {
        $comment = (string) new ReflectionClass(ViewExtension::class)->getDocComment();
        $rows = [];

        foreach (explode("\n", $comment) as $line) {
            if (1 !== preg_match('/^\s*\*( {5,})(\S.*)$/', $line, $match)) {
                continue;
            }

            // An example starts after five spaces, its description follows or goes on below.
            if (5 === \strlen($match[1]) && 1 === preg_match('/^(.*?(?:\}\}>?|%\}))(?:\s+(.*))?$/', $match[2], $parts)) {
                $rows[] = [trim($parts[1]), trim($parts[2] ?? '')];
            } elseif ([] !== $rows) {
                $rows[\count($rows) - 1][1] = trim($rows[\count($rows) - 1][1] . ' ' . $match[2]);
            }
        }

        $text = "Functions for the templates of blocks, layouts and partials, with an\n"
            . "example each. A block prints its fields with `field()` and its headings\n"
            . "with `heading()`, never with a fixed `<h1>` to `<h6>`: the core works out\n"
            . "the level from the place of the block.\n\n| Example | What it gives |\n|---|---|\n";

        foreach ($rows as [$code, $description]) {
            $text .= '| `' . self::cell($code) . '` | ' . self::cell(self::withoutDecisions($description)) . " |\n";
        }

        return self::page('Twig functions', $text);
    }

    private function fieldTypes(): string
    {
        $types = $this->registry->fieldTypes();
        ksort($types);
        $text = "Every field of a block, a website or an object has a type, e.g.\n"
            . "`title: { type: headline }`. Extensions add their own with\n"
            . "`\$registry->fieldType()` and `#[AsFieldType]`, named after their package.\n"
            . "Below each type: the format of its value, as a seed file or an action\n"
            . "gives it, its settings and what it prints.\n\n"
            . "| Type | Description |\n|---|---|\n";

        foreach ($types as $name => $class) {
            $reflection = new ReflectionClass($class);
            $attribute = $reflection->getAttributes(AsFieldType::class)[0] ?? null;
            $description = null === $attribute ? '' : $attribute->newInstance()->description;
            $description = '' === $description ? self::summary($reflection->getDocComment()) : self::withoutDecisions($description);
            $text .= '| `' . $name . '` | ' . self::cell($description) . " |\n";
        }

        foreach ($types as $name => $class) {
            $details = self::details(new ReflectionClass($class)->getDocComment());

            if ('' !== $details) {
                $text .= "\n## `" . $name . "`\n\n" . $details . "\n";
            }
        }

        return self::page('Field types', $text);
    }

    private static function events(): string
    {
        $text = "Events of the core that an extension can listen to:\n\n"
            . "```php\n\$registry->listen(ScheduleRan::class, PruneOldEntries::class);\n```\n\n"
            . "The listener is a class with `__invoke()` that takes the event; it is\n"
            . "loaded only when the event fires. Listeners change data only through\n"
            . "actions.\n";

        foreach (self::EVENTS as $class) {
            $reflection = new ReflectionClass($class);
            $text .= "\n## `" . $reflection->getShortName() . "`\n\n`" . $class . "`\n\n" . self::details($reflection->getDocComment()) . "\n";
            $constructor = $reflection->getConstructor();

            if (null !== $constructor && [] !== $constructor->getParameters()) {
                $text .= "\n| Property | Type |\n|---|---|\n";

                foreach ($constructor->getParameters() as $parameter) {
                    $text .= '| `$' . $parameter->getName() . '` | `' . self::cell((string) $parameter->getType()) . "` |\n";
                }
            }
        }

        return self::page('Events', $text);
    }

    private static function package(string $name): string
    {
        $slash = strrpos($name, '/');

        return false === $slash ? 'frootbox/core' : substr($name, 0, $slash);
    }

    private static function cell(string $text): string
    {
        return str_replace(['|', "\n"], ['\|', ' '], $text);
    }

    private static function page(string $title, string $text): string
    {
        return self::MARKER . "\n\n# " . $title . "\n\n" . rtrim($text) . "\n";
    }
}
