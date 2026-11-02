<?php

declare(strict_types=1);

namespace App\Form\Transformer;

use Symfony\Component\Form\DataTransformerInterface;

/**
 * Edits a list of short strings as one comma-separated text field.
 *
 * A CollectionType with add/remove buttons would need JavaScript to be usable; a plain text
 * field keeps the profile form working without any, which is the rule for this project.
 *
 * @implements DataTransformerInterface<list<string>, string>
 */
final readonly class CommaSeparatedListTransformer implements DataTransformerInterface
{
    public function transform(mixed $value): string
    {
        return \is_array($value) ? implode(', ', $value) : '';
    }

    /**
     * @return list<string>
     */
    public function reverseTransform(mixed $value): array
    {
        if (!\is_string($value) || '' === trim($value)) {
            return [];
        }

        $items = [];
        foreach (explode(',', $value) as $item) {
            $item = trim($item);
            if ('' !== $item) {
                $items[] = $item;
            }
        }

        return array_values(array_unique($items));
    }
}
