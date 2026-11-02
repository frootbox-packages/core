<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Queue;

use Frootbox\Core\Action\ActionName;
use Frootbox\Core\Action\DefinitionException;
use Frootbox\Core\Action\Schema\Fields;
use ReflectionClass;

/**
 * The registered jobs by name (decision 0221).
 */
final class JobClasses
{
    /** @var array<string, class-string<Job>> */
    private array $classes = [];

    /**
     * @param class-string<Job> $class
     * @param string|null       $package the package that registers it, null for the core
     */
    public function add(string $class, ?string $package): void
    {
        if (!is_subclass_of($class, Job::class)) {
            throw new DefinitionException(\sprintf('%s must implement %s.', $class, Job::class));
        }

        $job = self::attribute($class);

        if (!ActionName::isValid($job->name)) {
            throw new DefinitionException(\sprintf('The job %s has the invalid name "%s". Use lower case parts with dots, e.g. "order.sync".', $class, $job->name));
        }

        if (ActionName::package($job->name) !== $package) {
            throw new DefinitionException(null === $package
                ? \sprintf('The core job %s must not have a package in its name "%s".', $class, $job->name)
                : \sprintf('The job %s of %s must be named "%s/%s".', $class, $package, $package, ActionName::local($job->name)));
        }

        if ($job->attempts < 1) {
            throw new DefinitionException(\sprintf('The job %s needs at least one attempt.', $class));
        }

        if (isset($this->classes[$job->name]) && $this->classes[$job->name] !== $class) {
            throw new DefinitionException(\sprintf('The job "%s" is registered twice: %s and %s.', $job->name, $this->classes[$job->name], $class));
        }

        Fields::of($class);
        $this->classes[$job->name] = $class;
    }

    /**
     * @return class-string<Job>|null
     */
    public function get(string $name): ?string
    {
        return $this->classes[$name] ?? null;
    }

    /**
     * @return array<string, class-string<Job>>
     */
    public function all(): array
    {
        return $this->classes;
    }

    /**
     * @param class-string $class
     */
    public static function attribute(string $class): AsJob
    {
        $attribute = new ReflectionClass($class)->getAttributes(AsJob::class)[0] ?? null;

        if (null === $attribute) {
            throw new DefinitionException(\sprintf('%s needs the attribute #[%s].', $class, AsJob::class));
        }

        return $attribute->newInstance();
    }
}
