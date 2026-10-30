<?php

/*
 * SPDX-License-Identifier: LGPL-3.0-only
 */

declare(strict_types=1);

namespace Frootbox\Core\Database\Query;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Query\QueryBuilder;
use Frootbox\Core\Database\RepositoryException;
use Frootbox\Core\Database\Values;
use Frootbox\Core\Database\Where;

/**
 * Translates the options of Repository::fetch() into a query.
 *
 * where: ['status' => 'published']           status = 'published'
 *        ['parentId' => null]                 parent_id IS NULL
 *        ['id' => [1, 2, 3]]                  id IN (1, 2, 3)
 *        ['publishedAt <=' => $now]           also: = != <> < > >= like, not like, in, not in
 *        [Where::any([...], [...])]           OR between groups
 * order: 'title', 'title desc', ['sort', 'title desc'] or ['title' => 'desc']
 * limit, offset: numbers
 *
 * @internal
 */
final readonly class Criteria
{
    private const string KEY = '/^([A-Za-z_][A-Za-z0-9_]*)(?:\s+(=|!=|<>|<=|>=|<|>|like|not like|in|not in))?$/i';

    private const string ORDER = '/^([A-Za-z_][A-Za-z0-9_]*)(?:\s+(asc|desc))?$/i';

    private const array OPTIONS = ['where', 'order', 'limit', 'offset'];

    public function __construct(private QueryBuilder $query, private AbstractPlatform $platform) {}

    /**
     * @param array<string, mixed> $options
     */
    public function apply(array $options): void
    {
        $unknown = array_diff(array_keys($options), self::OPTIONS);

        if ([] !== $unknown) {
            throw new RepositoryException(\sprintf('Unknown option "%s". Allowed: %s.', implode('", "', $unknown), implode(', ', self::OPTIONS)));
        }

        $this->where(self::array($options['where'] ?? [], 'where'));
        $this->order($options['order'] ?? []);

        if (isset($options['limit'])) {
            $this->query->setMaxResults(self::number($options['limit'], 'limit'));
        }

        if (isset($options['offset'])) {
            $this->query->setFirstResult(self::number($options['offset'], 'offset'));
        }
    }

    /**
     * @param array<int|string, mixed> $where
     */
    public function where(array $where): void
    {
        $expression = $this->all($where);

        if (null !== $expression) {
            $this->query->where($expression);
        }
    }

    /**
     * @param array<int|string, mixed> $where
     */
    private function all(array $where): ?string
    {
        $parts = [];

        foreach ($where as $key => $value) {
            $parts[] = \is_int($key) ? $this->any($value) : $this->condition($key, $value);
        }

        return match (\count($parts)) {
            0 => null,
            1 => $parts[0],
            default => (string) $this->query->expr()->and(...$parts),
        };
    }

    private function any(mixed $value): string
    {
        if (!$value instanceof Where) {
            throw new RepositoryException('Entries without a column in "where" must be Where::any(...).');
        }

        $groups = array_map(fn(array $group): string => $this->all($group) ?? '1 = 1', $value->groups);

        return match (\count($groups)) {
            0 => '1 = 0',
            1 => $groups[0],
            default => (string) $this->query->expr()->or(...$groups),
        };
    }

    private function condition(string $key, mixed $value): string
    {
        if (1 !== preg_match(self::KEY, trim($key), $match)) {
            throw new RepositoryException(\sprintf('"%s" is not a valid condition. Use "column" or "column operator", e.g. "date >=".', $key));
        }

        $column = $this->platform->quoteSingleIdentifier(Values::column($match[1]));
        $operator = strtolower($match[2] ?? '=');
        $negated = \in_array($operator, ['!=', '<>', 'not in'], true);

        if (null === $value) {
            return match ($operator) {
                '=' => $column . ' IS NULL',
                '!=', '<>' => $column . ' IS NOT NULL',
                default => throw new RepositoryException(\sprintf('"%s" cannot be compared with null.', $key)),
            };
        }

        if (\is_array($value)) {
            if (!\in_array($operator, ['=', 'in', '!=', '<>', 'not in'], true)) {
                throw new RepositoryException(\sprintf('"%s" cannot be compared with a list.', $key));
            }

            if ([] === $value) {
                return $negated ? '1 = 1' : '1 = 0';
            }

            $values = array_values(array_map(Values::toDatabase(...), $value));
            $type = array_all($values, static fn(mixed $value): bool => \is_int($value)) ? ArrayParameterType::INTEGER : ArrayParameterType::STRING;

            return $column . ($negated ? ' NOT IN (' : ' IN (') . $this->query->createNamedParameter($values, $type) . ')';
        }

        if (\in_array($operator, ['in', 'not in'], true)) {
            throw new RepositoryException(\sprintf('"%s" needs a list of values.', $key));
        }

        $value = Values::toDatabase($value);
        $parameter = $this->query->createNamedParameter($value, \is_int($value) ? ParameterType::INTEGER : ParameterType::STRING);

        return $column . ' ' . strtoupper($operator) . ' ' . $parameter;
    }

    private function order(mixed $order): void
    {
        foreach (\is_string($order) ? [$order] : self::array($order, 'order') as $key => $value) {
            $entry = \is_string($key) && \is_string($value) ? $key . ' ' . $value : $value;

            if (!\is_string($entry) || 1 !== preg_match(self::ORDER, trim($entry), $match)) {
                throw new RepositoryException(\sprintf('"%s" is not a valid order. Use "column" or "column desc".', \is_string($entry) ? $entry : get_debug_type($entry)));
            }

            $this->query->addOrderBy($this->platform->quoteSingleIdentifier(Values::column($match[1])), strtoupper($match[2] ?? 'asc'));
        }
    }

    /**
     * @return array<int|string, mixed>
     */
    private static function array(mixed $value, string $option): array
    {
        if (!\is_array($value)) {
            throw new RepositoryException(\sprintf('The option "%s" must be an array.', $option));
        }

        return $value;
    }

    private static function number(mixed $value, string $option): int
    {
        if (!\is_int($value) || $value < 0) {
            throw new RepositoryException(\sprintf('The option "%s" must be a number from 0.', $option));
        }

        return $value;
    }
}
