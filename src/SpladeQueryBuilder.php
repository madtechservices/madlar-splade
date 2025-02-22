<?php

namespace ProtoneMedia\Splade;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\MySqlConnection;
use Illuminate\Database\PostgresConnection;
use Illuminate\Database\Query\Builder as BaseQueryBuilder;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Kirschbaum\PowerJoins\PowerJoins;
use ProtoneMedia\Splade\Table\Column;
use ProtoneMedia\Splade\Table\Filter;
use ProtoneMedia\Splade\Table\PowerJoinsException;
use ProtoneMedia\Splade\Table\SearchInput;
use Spatie\QueryBuilder\QueryBuilder as SpatieQueryBuilder;

class SpladeQueryBuilder extends SpladeTable
{
    private $paginateMethod;

    private $perPage;

    /**
     * Ignore case on searches.
     *
     * @var bool
     */
    private bool $ignoreCase = true;

    /**
     * Parse the search term into multiple terms.
     */
    protected bool $parseTerms = true;

    /**
     * Initializes this instance with an empty resource. The results will be
     * loaded when the Table components calls the beforeRender() method.
     *
     * @param  \Illuminate\Database\Query\Builder|\Illuminate\Database\Eloquent\Builder|\Spatie\QueryBuilder\QueryBuilder  $builder
     * @param  Request|null  $request
     */
    public function __construct(private BaseQueryBuilder|EloquentBuilder|SpatieQueryBuilder $builder, Request $request = null)
    {
        parent::__construct([], $request);
    }

    /**
     * Setter whether terms should be parsed.
     *
     * @param  bool  $state
     * @return $this
     */
    public function parseTerms(bool $state = true): self
    {
        $this->parseTerms = $state;

        return $this;
    }

    /**
     * Setter for ignoring case.
     *
     * @param  bool  $state
     * @return $this
     */
    public function ignoreCase(bool $state = true): self
    {
        $this->ignoreCase = $state;

        return $this;
    }

    public function noPagination(): self
    {
        return $this->setPagination('', null);
    }

    /**
     * Helper method to set the pagination method and per page value.
     *
     * @param  string  $method
     * @param  int|null  $perPage
     * @return $this
     */
    private function setPagination(string $method, ?int $perPage = null): self
    {
        $this->paginateMethod = $method;

        $this->perPage = $perPage;

        return $this;
    }

    /**
     * Use 'regular' length-aware pagination.
     *
     * @param  int  $perPage
     * @return $this
     */
    public function paginate($perPage = null): self
    {
        return $this->setPagination('paginate', $perPage);
    }

    /**
     * Use simple non-length-aware pagination.
     *
     * @param  int  $perPage
     * @return $this
     */
    public function simplePaginate($perPage = null): self
    {
        return $this->setPagination('simplePaginate', $perPage);
    }

    /**
     * Use cursor pagination.
     *
     * @param  int  $perPage
     * @return $this
     */
    public function cursorPaginate($perPage = null): self
    {
        return $this->setPagination('cursorPaginate', $perPage);
    }

    /**
     * Parse the terms and loop through them with the optional callable.
     *
     * @param  string  $terms
     * @return \Illuminate\Support\Collection
     */
    public function parseTermsIntoCollection(string $terms): Collection
    {
        return Collection::make(str_getcsv($terms, ' ', '"'))
            ->reject(function ($term = null) {
                return is_null($term) || trim($term) === '';
            })
            ->values();
    }

    /**
     * Formats the terms and returns the right where operator for the given search method.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $builder
     * @param  string  $term
     * @param  string|null  $searchMethod
     * @return array
     */
    private function getTermAndWhereOperator(EloquentBuilder $builder, string $term, ?string $searchMethod = null): array
    {
        $like = 'LIKE';

        if ($builder->getConnection() instanceof MySqlConnection) {
            $like = $this->ignoreCase ? 'LIKE' : 'LIKE BINARY';
        }

        if ($builder->getConnection() instanceof PostgresConnection) {
            $like = $this->ignoreCase ? 'ILIKE' : 'LIKE';
        }

        $searchMethod = $searchMethod ?: SearchInput::WILDCARD;

        return match ($searchMethod) {
            SearchInput::EXACT          => [$term, '='],
            SearchInput::WILDCARD       => ["%{$term}%", $like],
            SearchInput::WILDCARD_LEFT  => ["%{$term}", $like],
            SearchInput::WILDCARD_RIGHT => ["{$term}%", $like],
        };
    }

    private function applyConstraint(array $columns, string $terms)
    {
        $terms = $this->parseTerms
            ? $this->parseTermsIntoCollection($terms)
            : Collection::wrap($terms);

        // Start with a 'where' group, loop through all terms, and
        // add an 'orWhere' contraint for each column.
        $this->builder->where(function (EloquentBuilder $builder) use ($columns, $terms) {
            $terms->each(function (string $term) use ($builder, $columns) {
                Collection::wrap($columns)->each(function (?string $searchMethod, string $column) use ($builder, $term) {
                    [$term, $whereOperator] = $this->getTermAndWhereOperator($builder, $term, $searchMethod);

                    if (!Str::contains($column, '.')) {
                        // Not a relationship, but a column on the table.
                        return $builder->orWhere($builder->qualifyColumn($column), $whereOperator, $term);
                    }

                    // Split the column into the relationship name and the key on the relationship.
                    $relation = Str::beforeLast($column, '.');
                    $key      = Str::afterLast($column, '.');

                    $builder->orWhereHas($relation, function (EloquentBuilder $relation) use ($key, $whereOperator, $term) {
                        return $relation->where($relation->qualifyColumn($key), $whereOperator, $term);
                    });
                });
            });
        });
    }

    /**
     * Adds an "order by" clause to the query. If the query needs
     * to be sorted by a (nested) relationship, it will
     * verify the PowerJoins package is installed.
     *
     * @param  \ProtoneMedia\Splade\Table\Column  $column
     * @return void
     */
    private function applySorting(Column $column)
    {
        if (!$column->isNested()) {
            // Not a relationship, just a column on the table.
            return $this->builder->orderBy($column->key, $column->sorted);
        }

        if (!trait_exists(PowerJoins::class)) {
            throw new PowerJoinsException(
                "To order the query using a column from a relationship, please install the 'kirschbaum-development/eloquent-power-joins' package."
            );
        }

        if (!method_exists($this->builder->getModel(), 'scopeOrderByLeftPowerJoins')) {
            throw new PowerJoinsException(
                "To order the query using a column from a relationship, make sure the Model uses the 'PowerJoins' trait."
            );
        }

        // Apply the sorting using the PowerJoins package.
        return $this->builder->orderByLeftPowerJoins($column->key, $column->sorted);
    }

    /**
     * Loops through the active select filters and applies
     * them on the query builder.
     *
     * @return void
     */
    private function applyFilters()
    {
        $ignoreCaseSetting = $this->ignoreCase;
        $parseTermsSetting = $this->parseTerms;

        $this->ignoreCase(false);
        $this->parseTerms(false);

        $this->filters()->filter->hasValue()->each(
            function (Filter $filter){
                if($filter->type === 'date'){
                    $exploadDateRange = explode(' to ', request()->get('filter')['created_at']);
                    $from = "";
                    $to = "";
                    if(Carbon::parse($exploadDateRange[0])->isAfter(Carbon::parse($exploadDateRange[1]))){
                        $from = Carbon::parse($exploadDateRange[1])->toDateString();
                        $to = Carbon::parse($exploadDateRange[0])->toDateString();
                    }
                    else {
                        $from = Carbon::parse($exploadDateRange[0])->toDateString();
                        $to = Carbon::parse($exploadDateRange[1])->toDateString();
                    }
                    $this->builder->whereBetween(\DB::raw('DATE(created_at)') ,[$from,$to]);
                }
                else {
                    return $this->applyConstraint([$filter->key => SearchInput::EXACT], $filter->value);
                }
            }
        );

        $this->ignoreCase($ignoreCaseSetting);
        $this->parseTerms($parseTermsSetting);
    }

    /**
     * Loops through the active search inputs and applies
     * them on the query builder.
     *
     * @return void
     */
    private function applySearchInputs()
    {
        $this->searchInputs()->filter->value->each(
            fn (SearchInput $searchInput) => $this->applyConstraint($searchInput->columns, $searchInput->value)
        );
    }

    /**
     * Loops through the columns and checks whether the column is
     * the one to sort by.
     *
     * @return void
     */
    private function applySortingAndEagerLoading()
    {
        $this->columns()->each(function (Column $column) {
            if ($column->isNested()) {
                // Eager load the relationship.
                $this->builder->with($column->relationshipName());
            }

            if ($column->sorted) {
                $this->applySorting($column);
            }
        });
    }

    /**
     * Loads the results based on the pagination settings.
     *
     * @return void
     */
    private function loadResults()
    {
        if (!$this->paginateMethod) {
            // No pagination, so get all results.
            return $this->resource = $this->builder->get();
        }

        // The 'perPage' value is taken from the request query
        // string, or from the configured parameter, or it's
        // the first from the 'perPage' selector options.
        $defaultPerPage = $this->perPage ?: Arr::first($this->perPageOptions);

        $perPage = $this->query('perPage', $defaultPerPage);

        if (!in_array($perPage, $this->perPageOptions)) {
            // The 'perPage' value is not in the allowed options.
            // So we'll use the first option.
            $perPage = $defaultPerPage;
        }

        $this->resource = $this->builder->{$this->paginateMethod}($perPage)->withQueryString();
    }

    /**
     * Adds the given 'perPage' value to the 'perPageOptions' array.
     *
     * @return void
     */
    public function addCurrentPerPageValueToOptions()
    {
        if ($this->perPage && !in_array($this->perPage, $this->perPageOptions)) {
            $this->perPageOptions[] = $this->perPage;
        }
    }

    /**
     * Prepares the query and loads the results.
     *
     * @return void
     */
    public function beforeRender(): void
    {
        if (!$this->builder instanceof SpatieQueryBuilder) {
            $this->applyFilters();
            $this->applySearchInputs();
            $this->applySortingAndEagerLoading();
            $this->addCurrentPerPageValueToOptions();
        }

        $this->loadResults();
        $this->resolveRowLinks();
    }

    /**
     * Prepares the query for an export and returns the Builder.
     *
     * @return BaseQueryBuilder|EloquentBuilder|SpatieQueryBuilder
     */
    public function getBuilderForExport(): BaseQueryBuilder|EloquentBuilder|SpatieQueryBuilder
    {
        if (!$this->builder instanceof SpatieQueryBuilder) {
            $this->applyFilters();
            $this->applySearchInputs();
            $this->applySortingAndEagerLoading();
        }

        return $this->builder;
    }

    public function performBulkAction(callable $action, array $ids)
    {
        if (!$this->builder instanceof SpatieQueryBuilder) {
            $this->applySortingAndEagerLoading();
        }

        if (count($ids) === 1 && Arr::first($ids) === '*') {
            // all
        } else {
            $this->builder->whereKey($ids);
        }

        $this->builder->chunkById(1000, function (Collection $results) use ($action) {
            $results->each($action);
        });
    }
}
