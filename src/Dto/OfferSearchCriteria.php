<?php

declare(strict_types=1);

namespace App\Dto;

use App\Enum\ContractType;
use App\Enum\OfferStatus;
use App\Enum\Region;
use App\Enum\WorkMode;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Everything the offer list can be filtered and sorted by.
 *
 * Bound to a Symfony Form over GET, so the current search is always a shareable URL. The sort
 * is an enum-like whitelist rather than a column name: nothing here ever reaches SQL as an
 * identifier.
 */
final class OfferSearchCriteria
{
    public const string SORT_NEWEST = 'newest';
    public const string SORT_RELEVANCE = 'relevance';
    public const int PER_PAGE = 25;

    #[Assert\Length(max: 120)]
    public ?string $keyword = null;

    #[Assert\Length(max: 120)]
    public ?string $company = null;

    #[Assert\Length(max: 120)]
    public ?string $location = null;

    /** @var list<WorkMode> */
    public array $workModes = [];

    /** @var list<ContractType> */
    public array $contractTypes = [];

    public OfferStatus $status = OfferStatus::Open;

    public Region $region = Region::France;

    #[Assert\Choice(choices: [self::SORT_NEWEST, self::SORT_RELEVANCE])]
    public string $sort = self::SORT_NEWEST;

    /**
     * Opaque keyset cursor produced by the previous page. Never trusted: a malformed value is
     * simply ignored and the first page is returned.
     */
    public ?string $cursor = null;

    public function isEmpty(): bool
    {
        return null === $this->keyword
            && null === $this->company
            && null === $this->location
            && [] === $this->workModes
            && [] === $this->contractTypes;
    }

    /**
     * The shape stored in a SavedView. Enums are reduced to their backed values so the JSON
     * column stays readable and re-hydratable.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'keyword' => $this->keyword,
            'company' => $this->company,
            'location' => $this->location,
            'workModes' => array_map(static fn (WorkMode $mode): string => $mode->value, $this->workModes),
            'contractTypes' => array_map(static fn (ContractType $type): string => $type->value, $this->contractTypes),
            'status' => $this->status->value,
            'region' => $this->region->value,
            'sort' => $this->sort,
        ], static fn (mixed $value): bool => null !== $value && [] !== $value);
    }

    /**
     * Rebuilds the criteria from a stored view. Unknown or invalid values fall back to the
     * defaults instead of throwing: a saved view must never be able to break the search page.
     *
     * @param array<string, mixed> $filters
     */
    public static function fromArray(array $filters): self
    {
        $criteria = new self();
        $criteria->keyword = self::asString($filters['keyword'] ?? null);
        $criteria->company = self::asString($filters['company'] ?? null);
        $criteria->location = self::asString($filters['location'] ?? null);
        $criteria->workModes = self::asEnumList($filters['workModes'] ?? [], WorkMode::class);
        $criteria->contractTypes = self::asEnumList($filters['contractTypes'] ?? [], ContractType::class);
        $criteria->status = OfferStatus::tryFrom(self::asString($filters['status'] ?? null) ?? '') ?? OfferStatus::Open;
        $criteria->region = Region::tryFrom(self::asString($filters['region'] ?? null) ?? '') ?? Region::France;
        $sort = self::asString($filters['sort'] ?? null);
        $criteria->sort = \in_array($sort, [self::SORT_NEWEST, self::SORT_RELEVANCE], true) ? $sort : self::SORT_NEWEST;

        return $criteria;
    }

    private static function asString(mixed $value): ?string
    {
        return \is_string($value) && '' !== trim($value) ? trim($value) : null;
    }

    /**
     * @template T of \BackedEnum
     *
     * @param class-string<T> $enum
     *
     * @return list<T>
     */
    private static function asEnumList(mixed $values, string $enum): array
    {
        if (!\is_array($values)) {
            return [];
        }

        $cases = [];
        foreach ($values as $value) {
            if (\is_string($value) && null !== $case = $enum::tryFrom($value)) {
                $cases[] = $case;
            }
        }

        return $cases;
    }
}
