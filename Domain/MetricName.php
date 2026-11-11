<?php

declare(strict_types=1);

namespace Plugins\Usage\Domain;

/**
 * A metric name is a key in a table, in a queue payload and in somebody's alert
 * rule, so it is validated once, here, rather than trusted at each of those.
 *
 * Lower-case, starts with a letter, then letters, digits and `. _ : -`.
 * 128 characters is the column width: a longer name would be truncated on write
 * by MySQL in non-strict mode and silently become a DIFFERENT metric.
 */
final readonly class MetricName
{
    public const MAX_LENGTH = 128;

    public static function isValid(string $name): bool
    {
        return $name !== ''
            && \strlen($name) <= self::MAX_LENGTH
            && preg_match('/^[a-z][a-z0-9._:-]*$/', $name) === 1;
    }

    /** @throws \InvalidArgumentException */
    public static function assert(string $name): string
    {
        if (!self::isValid($name)) {
            throw new \InvalidArgumentException(
                'Invalid metric name [' . substr($name, 0, 40) . ']: lower-case letters, digits and . _ : -, '
                . 'starting with a letter, at most ' . self::MAX_LENGTH . ' characters.'
            );
        }

        return $name;
    }
}
