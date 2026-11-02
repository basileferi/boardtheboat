<?php

declare(strict_types=1);

namespace App\Service\Ingestion;

/**
 * Typed reads over decoded third-party JSON. Every payload is untrusted: a missing or mistyped
 * field yields null instead of a TypeError halfway through a batch.
 */
final class Payload
{
    /**
     * @param array<mixed> $data
     */
    public static function string(array $data, string ...$path): ?string
    {
        $value = self::dig($data, array_values($path));

        if (\is_int($value) || \is_float($value)) {
            return (string) $value;
        }

        if (!\is_string($value)) {
            return null;
        }

        $value = trim($value);

        return '' === $value ? null : $value;
    }

    /**
     * @param array<mixed> $data
     */
    public static function bool(array $data, string ...$path): bool
    {
        return true === self::dig($data, array_values($path));
    }

    /**
     * @param array<mixed> $data
     *
     * @return list<array<mixed>>
     */
    public static function rows(array $data, string ...$path): array
    {
        $value = [] === $path ? $data : self::dig($data, array_values($path));
        if (!\is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, \is_array(...)));
    }

    /**
     * Flat list of the non-empty strings found at a path, e.g. a list of tags.
     *
     * @param array<mixed> $data
     *
     * @return list<string>
     */
    public static function strings(array $data, string ...$path): array
    {
        $value = self::dig($data, array_values($path));
        if (!\is_array($value)) {
            return [];
        }

        $strings = [];
        foreach ($value as $item) {
            if (\is_string($item) && '' !== trim($item)) {
                $strings[] = trim($item);
            }
        }

        return $strings;
    }

    /**
     * Accepts an ISO-8601 string or a Unix timestamp.
     *
     * @param array<mixed> $data
     */
    public static function date(array $data, string ...$path): ?\DateTimeImmutable
    {
        $value = self::dig($data, array_values($path));

        try {
            return match (true) {
                \is_int($value) => new \DateTimeImmutable('@'.$value),
                \is_string($value) && '' !== $value => new \DateTimeImmutable($value),
                default => null,
            };
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * @param array<mixed> $data
     * @param list<string> $path
     */
    private static function dig(array $data, array $path): mixed
    {
        $value = $data;
        foreach ($path as $key) {
            if (!\is_array($value) || !\array_key_exists($key, $value)) {
                return null;
            }
            $value = $value[$key];
        }

        return $value;
    }
}
