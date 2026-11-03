<?php

/*
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Frootbox\Core\Seed;

use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\DBAL\Connection;
use Exception;
use Frootbox\Core\Action\ActionException;
use Frootbox\Core\Action\InvalidInputException;
use Frootbox\Core\Action\Normalizer;
use Frootbox\Core\Action\Runner;
use Frootbox\Core\Extension\Extensions;
use Frootbox\Core\Media\Uploads;
use Frootbox\Database\EntityNotFoundException;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Runs a list of actions and queries from a YAML file, one after the
 * other (decision 0109):
 *
 *     - page.create: { layout: acme/site/Page, translations: { de: { title: Start } } }
 *       as: start
 *     - page.publish: { ids: ['${start.id}'] }
 *
 * "${name.path}" puts in a value from an earlier result: alone in a string
 * it keeps its type, inside a text it becomes text.
 *
 * "${date:+2 weeks 18:00}" is a time relative to now in the time zone of
 * PHP, as "2026-11-17T18:00:00+01:00", e.g. for demo events that stay
 * ahead (decision 0227). Any relative format of PHP works.
 *
 * The step "upload" keeps a file like POST /api/v1/uploads and gives its
 * token, e.g. for media.create; the path is relative to the seed file or
 * "vendor/package:path":
 *
 *     - upload: { file: media/roastery.jpg }
 *       as: roastery
 *     - media.create: { upload: '${roastery.token}' }
 *
 * All steps run in one transaction: if one fails, the database is as
 * before, so the file can be fixed and run again (decision 0194).
 */
final readonly class Seeder
{
    /** The step that keeps a file and gives its token. */
    public const string UPLOAD = 'upload';

    private const string NAME = '[A-Za-z0-9_-]+';

    public function __construct(
        private Runner $runner,
        private Extensions $extensions,
        private Uploads $uploads,
        private Connection $connection,
    ) {}

    /**
     * The file of a path or of "vendor/package:path" in an extension.
     *
     * @throws SeedException
     */
    public function file(string $path, string $directory): string
    {
        if (1 === preg_match('#^([a-z0-9_.-]+/[a-z0-9_.-]+):(.+)$#', $path, $match)) {
            $package = $this->extensions->get($match[1]) ?? throw new SeedException(\sprintf('There is no extension %s.', $match[1]));
            $path = $package->path . '/' . ltrim($match[2], '/');
        } elseif (!str_starts_with($path, '/')) {
            $path = $directory . '/' . $path;
        }

        return is_file($path) ? $path : throw new SeedException(\sprintf('There is no file %s.', $path));
    }

    /**
     * Reads the steps of a seed file.
     *
     * @return list<array{string, array<string, mixed>, string|null}> Name, input and "as" of each step
     *
     * @throws SeedException
     */
    public function read(string $file): array
    {
        try {
            $data = Yaml::parseFile($file);
        } catch (ParseException $error) {
            throw new SeedException($error->getMessage(), previous: $error);
        }

        if (!\is_array($data) || !array_is_list($data)) {
            throw new SeedException(\sprintf('%s must be a list of steps, e.g. "- page.create: { … }".', $file));
        }

        $steps = [];

        foreach ($data as $index => $step) {
            $step = self::step($step, $index + 1);

            if (self::UPLOAD === $step[0]) {
                $step[1] = ['file' => $this->upload($step[1], \dirname($file), $index + 1)];
            }

            $steps[] = $step;
        }

        return $steps;
    }

    /**
     * Runs the steps.
     *
     * @param list<array{string, array<string, mixed>, string|null}> $steps
     * @param (callable(int, string): void)|null                     $progress Called before each step with its number and name
     *
     * @return array<string, mixed> The results remembered with "as"
     *
     * @throws SeedException
     */
    public function run(array $steps, ?callable $progress = null): array
    {
        return $this->connection->transactional(fn(): array => $this->runSteps($steps, $progress));
    }

    /**
     * @param list<array{string, array<string, mixed>, string|null}> $steps
     * @param (callable(int, string): void)|null                     $progress
     *
     * @return array<string, mixed>
     *
     * @throws SeedException
     */
    private function runSteps(array $steps, ?callable $progress): array
    {
        $results = [];

        foreach ($steps as $index => [$name, $input, $as]) {
            $number = $index + 1;

            if (null !== $progress) {
                $progress($number, $name);
            }

            try {
                $map = [];

                foreach ($input as $key => $value) {
                    $map[$key] = self::fill($value, $results, $number);
                }

                $result = self::UPLOAD === $name ? $this->store($map) : Normalizer::normalize($this->runner->call($name, $map));
            } catch (InvalidInputException $error) {
                throw new SeedException(\sprintf(
                    "Step %d (%s) has invalid input:\n%s",
                    $number,
                    $name,
                    implode("\n", array_map(static fn(string $pointer, string $message): string => '  ' . ('' === $pointer ? '/' : $pointer) . ': ' . $message, array_keys($error->errors), $error->errors)),
                ), previous: $error);
            } catch (ActionException|EntityNotFoundException $error) {
                throw new SeedException(\sprintf('Step %d (%s) failed: %s', $number, $name, $error->getMessage()), previous: $error);
            }

            if (null !== $as) {
                $results[$as] = $result;
            }
        }

        return $results;
    }

    /**
     * Keeps the file of an upload step, a copy, and gives its token.
     *
     * @param array<string, mixed> $input
     *
     * @return array{token: string}
     */
    private function store(array $input): array
    {
        $file = $input['file'] ?? null;

        if (!\is_string($file)) {
            throw new SeedException('An upload needs a file.');
        }

        return ['token' => $this->uploads->store($file, basename($file), move: false)];
    }

    /**
     * The file of an upload step.
     *
     * @param array<string, mixed> $input
     *
     * @throws SeedException
     */
    private function upload(array $input, string $directory, int $number): string
    {
        $file = $input['file'] ?? null;

        if (!\is_string($file) || '' === $file || 1 !== \count($input)) {
            throw new SeedException(\sprintf('Step %d: "upload" takes only "file", e.g. "upload: { file: media/roastery.jpg }".', $number));
        }

        try {
            return $this->file($file, $directory);
        } catch (SeedException $error) {
            throw new SeedException(\sprintf('Step %d: %s', $number, $error->getMessage()), previous: $error);
        }
    }

    /**
     * @return array{string, array<string, mixed>, string|null}
     */
    private static function step(mixed $step, int $number): array
    {
        if (!\is_array($step) || array_is_list($step)) {
            throw new SeedException(\sprintf('Step %d must be an object such as "page.create: { … }".', $number));
        }

        $as = $step['as'] ?? null;
        unset($step['as']);

        if (null !== $as && (!\is_string($as) || 1 !== preg_match('/^' . self::NAME . '$/', $as))) {
            throw new SeedException(\sprintf('Step %d: "as" must be a name of letters, digits, "-" and "_".', $number));
        }

        if (1 !== \count($step)) {
            throw new SeedException(\sprintf('Step %d must name exactly one action or query, besides "as". Found: %s.', $number, [] === $step ? 'none' : implode(', ', array_keys($step))));
        }

        $name = (string) array_key_first($step);
        $input = $step[$name] ?? [];

        if (!\is_array($input) || ([] !== $input && array_is_list($input))) {
            throw new SeedException(\sprintf('Step %d: the input of %s must be an object.', $number, $name));
        }

        $map = [];

        foreach ($input as $key => $value) {
            $map[(string) $key] = $value;
        }

        return [$name, $map, $as];
    }

    /**
     * @param array<string, mixed> $results
     */
    private static function fill(mixed $value, array $results, int $number): mixed
    {
        if (\is_array($value)) {
            return array_map(static fn(mixed $item): mixed => self::fill($item, $results, $number), $value);
        }

        if (!\is_string($value) || !str_contains($value, '${')) {
            return $value;
        }

        $value = (string) preg_replace_callback('/\$\{date:([^}]+)\}/', static function (array $match) use ($number): string {
            try {
                return new DateTimeImmutable(trim($match[1]))->format(DateTimeInterface::ATOM);
            } catch (Exception) {
                throw new SeedException(\sprintf('Step %d: ${date:%s} is no time, e.g. ${date:+2 weeks 18:00}.', $number, $match[1]));
            }
        }, $value);

        if (!str_contains($value, '${')) {
            return $value;
        }

        $reference = '\$\{(' . self::NAME . '(?:\.' . self::NAME . ')*)\}';

        if (1 === preg_match('/^' . $reference . '$/', $value, $match)) {
            return self::lookup($match[1], $results, $number);
        }

        return preg_replace_callback('/' . $reference . '/', static function (array $match) use ($results, $number): string {
            $found = self::lookup($match[1], $results, $number);

            return \is_scalar($found) ? (string) $found : throw new SeedException(\sprintf('Step %d: ${%s} is no text or number.', $number, $match[1]));
        }, $value);
    }

    /**
     * @param array<string, mixed> $results
     */
    private static function lookup(string $path, array $results, int $number): mixed
    {
        $value = $results;

        foreach (explode('.', $path) as $key) {
            if (!\is_array($value) || !\array_key_exists($key, $value)) {
                throw new SeedException(\sprintf('Step %d: ${%s} is unknown. Remembered so far: %s.', $number, $path, [] === $results ? 'nothing' : implode(', ', array_keys($results))));
            }

            $value = $value[$key];
        }

        return $value;
    }
}
