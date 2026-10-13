<?php

declare(strict_types=1);

namespace Valencio\LaravelToolkit\Crud\Query;

use Valencio\LaravelToolkit\Crud\Support\CrudValidation;

/**
 * 已验证查询输入的标准化对象。
 *
 * fromValidated 只做规范化与基本边界；业务键白名单仍由 FormRequest / QueryApplier 负责。
 */
final readonly class QueryInput
{
    /**
     * @param  array<string, mixed>  $filters
     * @param  list<array{alias: string, direction: 'asc'|'desc'}>  $sorts
     */
    public function __construct(
        public array $filters,
        public array $sorts,
        public ?int $page,
        public ?int $perPage,
        public ?int $limit,
        public bool $pageProvided,
        public bool $perPageProvided,
        public bool $limitProvided,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public static function fromValidated(array $input, ?QueryLimits $limits = null): self
    {
        $limits ??= QueryLimits::fromConfig();

        $filters = self::normalizeFilters($input['filter'] ?? [], $limits);
        $sorts = self::normalizeSorts($input['sort'] ?? null, $limits);

        $pageProvided = array_key_exists('page', $input);
        $perPageProvided = array_key_exists('per_page', $input);
        $limitProvided = array_key_exists('limit', $input);

        $page = $pageProvided ? self::requirePositiveInt($input['page'], 'page') : null;
        $perPage = $perPageProvided
            ? self::requireBoundedPositiveInt($input['per_page'], 'per_page', $limits->maxPageSize)
            : null;
        $limit = $limitProvided
            ? self::requireBoundedPositiveInt($input['limit'], 'limit', $limits->maxOptionsLimit)
            : null;

        return new self(
            filters: $filters,
            sorts: $sorts,
            page: $page,
            perPage: $perPage,
            limit: $limit,
            pageProvided: $pageProvided,
            perPageProvided: $perPageProvided,
            limitProvided: $limitProvided,
        );
    }

    /**
     * 分页入口：补默认 page/per_page；拒绝 limit。
     */
    public function forPagination(?QueryLimits $limits = null): self
    {
        $limits ??= QueryLimits::fromConfig();

        if ($this->limitProvided) {
            CrudValidation::throw([
                'limit' => ['Pagination queries must not include limit.'],
            ]);
        }

        return new self(
            filters: $this->filters,
            sorts: $this->sorts,
            page: $this->page ?? 1,
            perPage: $this->perPage ?? $limits->defaultPageSize,
            limit: null,
            pageProvided: true,
            perPageProvided: true,
            limitProvided: false,
        );
    }

    /**
     * Options 入口：补默认 limit；拒绝 page/per_page。
     */
    public function forOptions(?QueryLimits $limits = null): self
    {
        $limits ??= QueryLimits::fromConfig();

        if ($this->pageProvided || $this->perPageProvided) {
            CrudValidation::throw([
                'page' => ['Options queries must not include page or per_page.'],
            ]);
        }

        return new self(
            filters: $this->filters,
            sorts: $this->sorts,
            page: null,
            perPage: null,
            limit: $this->limit ?? $limits->defaultOptionsLimit,
            pageProvided: false,
            perPageProvided: false,
            limitProvided: true,
        );
    }

    /**
     * @param  mixed  $raw
     * @return array<string, mixed>
     */
    private static function normalizeFilters(mixed $raw, QueryLimits $limits): array
    {
        if ($raw === null) {
            return [];
        }

        if (! is_array($raw)) {
            CrudValidation::throw([
                'filter' => ['Filter must be an object/map of named rules.'],
            ]);
        }

        if (count($raw) > $limits->maxFilters) {
            CrudValidation::throw([
                'filter' => ["At most {$limits->maxFilters} filters are allowed."],
            ]);
        }

        $filters = [];
        foreach ($raw as $name => $value) {
            if (! is_string($name) || $name === '') {
                CrudValidation::throw([
                    'filter' => ['Filter names must be non-empty strings.'],
                ]);
            }
            $filters[$name] = $value;
        }

        return $filters;
    }

    /**
     * @return list<array{alias: string, direction: 'asc'|'desc'}>
     */
    private static function normalizeSorts(mixed $raw, QueryLimits $limits): array
    {
        if ($raw === null || $raw === '') {
            return [];
        }

        if (! is_string($raw)) {
            CrudValidation::throw([
                'sort' => ['Sort must be a comma-separated list of aliases.'],
            ]);
        }

        $parts = array_values(array_filter(
            array_map(static fn (string $part): string => trim($part), explode(',', $raw)),
            static fn (string $part): bool => $part !== '',
        ));

        if (count($parts) > $limits->maxSorts) {
            CrudValidation::throw([
                'sort' => ["At most {$limits->maxSorts} sort fields are allowed."],
            ]);
        }

        $sorts = [];
        foreach ($parts as $part) {
            $direction = str_starts_with($part, '-') ? 'desc' : 'asc';
            $alias = ltrim($part, '-');
            if ($alias === '') {
                CrudValidation::throw([
                    'sort' => ['Sort aliases must not be empty.'],
                ]);
            }
            $sorts[] = ['alias' => $alias, 'direction' => $direction];
        }

        return $sorts;
    }

    private static function requirePositiveInt(mixed $value, string $field): int
    {
        if (! is_int($value) || $value < 1) {
            CrudValidation::throw([
                $field => ["The {$field} must be a positive integer."],
            ]);
        }

        return $value;
    }

    private static function requireBoundedPositiveInt(mixed $value, string $field, int $max): int
    {
        $int = self::requirePositiveInt($value, $field);
        if ($int > $max) {
            CrudValidation::throw([
                $field => ["The {$field} may not be greater than {$max}."],
            ]);
        }

        return $int;
    }
}
