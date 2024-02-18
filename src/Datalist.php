<?php

declare(strict_types = 1);

namespace Datalist;

use Nette\Application\UI\Control;
use Nette\Utils\Arrays;
use Nette\Utils\Paginator;
use Nette\Utils\Strings;
use StORM\Collection;
use StORM\ISearchableCollection;

/**
 * @template T of object
 * @property array<callable(static): void> $onAnchor
 * @method onLoad(\StORM\ISearchableCollection $source)
 * @method onSaveState(\Datalist\Datalist $param, array $params)
 * @method onLoadState(\Datalist\Datalist $param, array $params)
 */
class Datalist extends Control
{
	/**
	 * @var array<callable(\StORM\ISearchableCollection): void> Occurs before data is load
	 */
	public array $onLoad = [];
	
	/**
	 * @var array<callable(static, array<mixed> ): void> Occurs before state is loaded
	 */
	public array $onLoadState = [];
	
	/**
	 * @var array<callable(static, array<mixed> ): void> Occurs after state is save
	 */
	public array $onSaveState = [];
	
	/**
	 * @persistent
	 */
	public ?string $order = null;
	
	/**
	 * @persistent
	 */
	public ?int $page = null;
	
	/**
	 * @persistent
	 */
	public ?int $onpage = null;
	
	protected ?int $defaultOnPage = null;
	
	protected ?string $defaultOrder = null;
	
	protected string $defaultDirection = 'ASC';
	
	/**
	 * @var array<string>
	 */
	protected array $secondaryOrder = [];
	
	/**
	 * @var array<string>
	 */
	protected array $allowedOrderColumn = [];
	
	/**
	 * @var array<string, callable>
	 */
	protected array $orderExpressions = [];
	
	/**
	 * @var array<string, callable>
	 */
	protected array $filterExpressions = [];
	
	/**
	 * @var array<mixed>|array<null>
	 */
	protected array $filterDefaultValue = [];
	
	/**
	 * @var array<string>
	 */
	protected array $allowedRepositoryFilters = [];
	
	/**
	 * @var array<string, mixed>
	 */
	protected array $filters = [];
	
	/**
	 * @var ?callable
	 */
	protected $outputFilter;
	
	protected bool $autoCanonicalize = false;
	
	protected ?Paginator $paginator = null;
	
	/**
	 * @var \StORM\ISearchableCollection<T>
	 */
	protected ISearchableCollection $collection;
	
	/**
	 * @var \StORM\ISearchableCollection<T>|null
	 */
	protected ?ISearchableCollection $filteredSource = null;
	
	/**
	 * @var array<string|int, T>|null
	 */
	protected ?array $objectsOnPage = null;
	
	/**
	 * @var callable|null
	 */
	protected $nestingCallback = null;
	
	/**
	 * @var callable|null
	 */
	protected $itemCountCallback = null;
	
	/**
	 * @var array<bool>
	 */
	private array $statefulFilters = [];
	
	/**
	 * @param \StORM\ISearchableCollection<T> $collection
	 * @param int|null $defaultOnPage
	 * @param string|null $defaultOrderExpression
	 * @param string|null $defaultOrderDir
	 */
	public function __construct(ISearchableCollection $collection, ?int $defaultOnPage = null, ?string $defaultOrderExpression = null, ?string $defaultOrderDir = null)
	{
		$this->collection = $collection;
		
		$this->itemCountCallback = function (ISearchableCollection $filteredSource) {
			return $filteredSource->count();
		};
		
		if ($defaultOnPage !== null) {
			$this->setDefaultOnPage($defaultOnPage);
		}
		
		if ($defaultOrderExpression !== null) {
			$this->setDefaultOrder($defaultOrderExpression, $defaultOrderDir ?: $this->defaultDirection);
		}
		
		if (!($collection instanceof Collection)) {
			return;
		}
		
		foreach ($collection->getRepository()->getStructure()->getColumns(true) as $column) {
			if ($column->hasMutations()) {
				$this->allowedOrderColumn[$column->getPropertyName()] = $collection->getPrefix(true) . $column->getName() . $collection->getConnection()->getMutationSuffix();
				
				foreach (\array_keys($collection->getConnection()->getAvailableMutations()) as $suffix) {
					$this->allowedOrderColumn[$column->getPropertyName() . $suffix] = $collection->getPrefix(true) . $column->getName() . $suffix;
				}
			} else {
				$this->allowedOrderColumn[$column->getPropertyName()] = $collection->getPrefix(true) . $column->getName();
			}
		}
	}
	
	public function setDefaultOnPage(?int $onPage): void
	{
		$this->defaultOnPage = $onPage;
	}
	
	public function getDefaultOnPage(): ?int
	{
		return $this->defaultOnPage;
	}
	
	/**
	 * @return array{0: string|null, 1: string}
	 */
	public function getDefaultOrder(): array
	{
		return [$this->defaultOrder, $this->defaultDirection];
	}
	
	public function setDefaultOrder(?string $name, string $direction = 'ASC'): void
	{
		$this->defaultOrder = $name;
		$this->defaultDirection = $direction;
	}
	
	/**
	 * @param array<string> $orderBy
	 */
	public function setSecondaryOrder(array $orderBy): void
	{
		$this->secondaryOrder = $orderBy;
	}
	
	public function getDirection(bool $reverse = false): string
	{
		if ($this->order === null) {
			$orderDirection = $this->defaultDirection;
		} else {
			// phpcs:ignore
			@[$name, $orderDirection] = \explode('-', $this->order);
			unset($name);
		}
		
		if ($reverse) {
			return Strings::upper($orderDirection) === 'ASC' ? 'DESC' : 'ASC';
		}
		
		return Strings::upper($orderDirection) === 'ASC' ? 'ASC' : 'DESC';
	}
	
	public function getOrder(): ?string
	{
		if ($this->order === null) {
			return $this->defaultOrder;
		}
		
		// phpcs:ignore
		@[$name, $direction] = \explode('-', $this->order);
		unset($direction);
		
		return $name;
	}
	
	public function getOrderParameter(): string
	{
		return $this->getOrder() . '-' . $this->getDirection();
	}
	
	public function isOrderBy(string $order, ?string $direction = null): bool
	{
		return $order === $this->getOrder() && ($direction === null || $direction === $this->getDirection());
	}
	
	/**
	 * @param array<string> $columns
	 * @param bool $merge
	 */
	public function setAllowedOrderColumns(array $columns, bool $merge = false): void
	{
		$this->allowedOrderColumn = $merge ? $this->allowedOrderColumn + $columns : $columns;
	}

	public function isAllowedOrderColumn(string $column): bool
	{
		return isset($this->allowedOrderColumn[$column]);
	}
	
	public function addOrderExpression(string $name, callable $callback): void
	{
		$this->orderExpressions[$name] = $callback;
	}
	
	/**
	 * @param array<string> $listToRemove
	 */
	public function removeOrderExpressions(array $listToRemove): void
	{
		foreach ($listToRemove as $name) {
			unset($this->orderExpressions[$name]);
		}
	}
	
	public function setOrder(?string $name, string $direction = 'ASC'): void
	{
		$this->order = $name . '-' . $direction;
	}
	
	/**
	 * @param string $name
	 * @param callable $callback
	 * @param mixed $defaultValue
	 */
	public function addFilterExpression(string $name, callable $callback, $defaultValue = null): void
	{
		$this->filterExpressions[$name] = $callback;
		$this->filterDefaultValue[$name] = $defaultValue;
	}
	
	/**
	 * @param array<string> $listToRemove
	 */
	public function removeFilterExpressions(array $listToRemove): void
	{
		foreach ($listToRemove as $name) {
			unset($this->filterExpressions[$name]);
			unset($this->filterDefaultValue[$name]);
		}
	}
	
	/**
	 * @param array<string> $list
	 * @param bool $merge
	 */
	public function setAllowedRepositoryFilters(array $list, bool $merge = false): void
	{
		$this->allowedRepositoryFilters = $merge ? $this->allowedRepositoryFilters + $list : $list;
	}
	
	/**
	 * @param array<mixed> $filters
	 */
	public function setFilters(?array $filters): void
	{
		if ($filters === null) {
			$this->filters = [];
			
			return;
		}
		
		foreach ($filters as $name => $value) {
			if ($value !== null) {
				$this->filters[$name] = $value;
			} else {
				unset($this->filters[$name]);
			}
		}
	}
	
	/**
	 * @return array<string, mixed>
	 */
	public function getFilters(): array
	{
		return $this->filters;
	}
	
	public function getFilterDefaultValue(string $filter): ?string
	{
		return $this->filterDefaultValue[$filter] ?? null;
	}
	
	public function setPage(int $page): void
	{
		$this->page = $page;
	}
	
	public function getPage(): int
	{
		return $this->page ?: 1;
	}
	
	public function setOnPage(?int $onPage): void
	{
		$this->onpage = $onPage;
	}
	
	public function getOnPage(): ?int
	{
		return $this->onpage ?: $this->defaultOnPage;
	}
	
	/**
	 * @param array<mixed> $params
	 */
	public function loadState(array $params): void
	{
		$this->onLoadState($this, $params);
		
		parent::loadState($params);
		
		foreach ($params as $name => $value) {
			if (isset($this->filterExpressions[$name])) {
				$this->filters[$name] = $value;
				$this->statefulFilters[$name] = true;
			}
		}
		
		foreach (\array_keys($this->filterExpressions) as $name) {
			if ((!isset($params[$name]) || $params[$name] === $this->filterDefaultValue[$name]) && isset($this->statefulFilters[$name])) {
				unset($this->filters[$name], $this->statefulFilters[$name]);
			}
		}
	}
	
	/**
	 * @param array<mixed> $params
	 */
	public function saveState(array &$params): void
	{
		parent::saveState($params);
		
		$this->onSaveState($this, $params);
		
		if ($this->autoCanonicalize) {
			if (isset($params['onpage']) && $this->defaultOnPage === (int) $params['onpage']) {
				$params['onpage'] = null;
			}
			
			if (isset($params['order']) && $this->defaultOrder !== null && ($this->defaultOrder . '-' . $this->defaultDirection) === $params['order']) {
				$params['order'] = null;
			}
			
			if (isset($params['page']) && (int) $params['page'] === 1) {
				$params['page'] = null;
			}
		}
		
		if (!$this->filters) {
			return;
		}
		
		foreach ($this->filters as $filter => $value) {
			if (isset($this->statefulFilters[$filter])) {
				$params[$filter] = $value;
			}
		}
	}
	
	public function setAutoCanonicalize(bool $enabled): void
	{
		$this->autoCanonicalize = $enabled;
	}
	
	/**
	 * @deprecated use getCollection() instead
	 * @param bool $newInstance
	 */
	public function getSource(bool $newInstance = true): ISearchableCollection
	{
		return $this->getCollection($newInstance);
	}
	
	/**
	 * @deprecated use getFiltereCollection() instead
	 * @param bool $newInstance
	 */
	public function getFilteredSource(bool $newInstance = true): ISearchableCollection
	{
		return $this->getFilteredCollection($newInstance);
	}
	
	/**
	 * @param bool $newInstance
	 * @return \StORM\ISearchableCollection<T>
	 */
	public function getCollection(bool $newInstance = true): ISearchableCollection
	{
		return $newInstance ? clone $this->collection : $this->collection;
	}
	
	/**
	 * @param bool $newInstance
	 * @return \StORM\ISearchableCollection<T>
	 */
	public function getFilteredCollection(bool $newInstance = true): ISearchableCollection
	{
		if ($this->filteredSource && !$newInstance) {
			return $this->filteredSource;
		}
		
		$filteredSource = $this->getCollection();
		
		// FILTER
		foreach ($this->filters as $name => $value) {
			if ($filteredSource instanceof Collection && !isset($this->filterExpressions[$name]) && Arrays::contains($this->allowedRepositoryFilters, $name)) {
				$filteredSource->filter([$name => $value]);
			}
			
			if (!isset($this->filterExpressions[$name]) || $this->filterDefaultValue[$name] === $value) {
				continue;
			}
			
			\call_user_func_array($this->filterExpressions[$name], [$filteredSource, $value]);
		}
		
		// ORDER BY IF NOT SET IN COLLECTION
		if ($this->getOrder() !== null && !($filteredSource->getModifiers()['ORDER BY'] && !$this->order)) {
			$filteredSource->setOrderBy([]);
			
			if (isset($this->orderExpressions[$this->getOrder()])) {
				\call_user_func_array($this->orderExpressions[$this->getOrder()], [$filteredSource, $this->getDirection()]);
			}
			
			if ($this->isAllowedOrderColumn($this->getOrder())) {
				$filteredSource->orderBy([$this->allowedOrderColumn[$this->getOrder()] => $this->getDirection()]);
			}
			
			$filteredSource->orderBy($this->secondaryOrder);
		}
		
		if ($newInstance) {
			return $filteredSource;
		}
		
		return $this->filteredSource = $filteredSource;
	}
	
	/**
	 * @return T|null
	 */
	public function getFirstObject(): ?object
	{
		if ($this->getPaginator()->isFirst()) {
			return Arrays::first($this->getObjectsOnPage());
		}
		
		$collection = $this->getFilteredCollection();
		
		if ($this->getOrder()) {
			$collection->setOrderBy([$this->getOrder() => $this->getDirection()]);
		}
		
		return $collection->first();
	}
	
	/**
	 * @return T|null
	 */
	public function getLastObject(): ?object
	{
		if ($this->getPaginator()->isLast()) {
			return Arrays::last($this->getObjectsOnPage());
		}
		
		$collection = $this->getFilteredCollection();
		
		if ($this->getOrder()) {
			$collection->setOrderBy([$this->getOrder() => $this->getDirection(true)]);
		}
		
		return $collection->first();
	}
	
	public function setItemCountCallback(callable $callback): void
	{
		$this->itemCountCallback = $callback;
	}
	
	public function getPaginator(bool $refresh = false): \Nette\Utils\Paginator
	{
		if ($this->paginator && !$refresh) {
			return $this->paginator;
		}
		
		$this->paginator = new Paginator();
		
		$this->paginator->setPage($this->getPage());
		
		if ($this->itemCountCallback !== null) {
			$this->paginator->setItemCount(\call_user_func($this->itemCountCallback, $this->getFilteredCollection()));
		}
		
		$this->paginator->setItemsPerPage($this->getOnPage() ?: \intval($this->paginator->getItemCount()));
		
		return $this->paginator;
	}
	
	public function setOutputFilter(?callable $outputFilter): void
	{
		$this->outputFilter = $outputFilter;
	}
	
	/**
	 * @return array<string|int, T>
	 */
	public function getObjectsOnPage(): array
	{
		if ($this->objectsOnPage !== null) {
			return $this->objectsOnPage;
		}
		
		$source = $this->getFilteredCollection();
		
		if ($this->getOnPage()) {
			$source->setPage($this->getPage(), $this->getOnPage());
		}
		
		$this->onLoad($source);

		$this->objectsOnPage = $this->nestingCallback && !$this->filters
			? $this->getNestedCollection($source, null) : ($this->outputFilter ? \array_map($this->outputFilter, $source->toArray()) : $source->toArray());
		
		return $this->objectsOnPage;
	}
	
	/**
	 * @deprecated Use getObjectsOnPage instead
	 * @return array<\StORM\Entity>|array<object>
	 */
	public function getItemsOnPage(): array
	{
		return $this->getObjectsOnPage();
	}
	
	public function setNestingCallback(callable $callback): void
	{
		$this->nestingCallback = $callback;
	}
	
	/**
	 * @param \Datalist\Datalist $datalist
	 * @param array<mixed> $params
	 * @param \Nette\Http\SessionSection<mixed> $section
	 */
	public static function loadSession(Datalist $datalist, array $params, \Nette\Http\SessionSection $section): void
	{
		if (!isset($params['page']) && isset($section->page)) {
			$datalist->page = $section->page;
		}
		
		unset($params['page']);
		
		if (!isset($params['onpage']) && isset($section->onpage)) {
			$datalist->onpage = $section->onpage;
		}
		
		unset($params['onpage']);
		
		if (!isset($params['order']) && isset($section->order)) {
			$datalist->order = $section->order;
		}
		
		unset($params['order']);
		
		if (!isset($section->filters)) {
			return;
		}
		
		$datalist->filters = $section->filters;
	}
	
	/**
	 * @param \Datalist\Datalist $datalist
	 * @param array<mixed> $params
	 * @param \Nette\Http\SessionSection<mixed> $section
	 */
	public static function saveSession(Datalist $datalist, array $params, \Nette\Http\SessionSection $section): void
	{
		if (isset($params['page'])) {
			$section->page = $params['page'];
		} else {
			unset($section->page);
		}
		
		unset($params['page']);
		
		if (isset($params['onpage'])) {
			$section->onpage = $params['onpage'];
		} else {
			unset($section->onpage);
		}
		
		unset($params['onpage']);
		
		if (isset($params['order'])) {
			$section->order = $datalist->getOrderParameter();
		} else {
			unset($section->order);
		}
		
		unset($params['order']);
		
		$section->filters = $datalist->getFilters();
	}
	
	/**
	 * @param \StORM\ISearchableCollection $source
	 * @param \StORM\Entity|object|null $parent
	 * @return array<\StORM\Entity>|array<object>
	 */
	protected function getNestedCollection(ISearchableCollection $source, ?object $parent): array
	{
		if ($this->nestingCallback === null) {
			throw new \DomainException('Nesting callback is not set');
		}
		
		$items = [];
		\call_user_func_array($this->nestingCallback, [$source, $parent]);
		
		/* @phpstan-ignore-next-line */
		foreach ($source as $key => $item) {
			$items[$key] = $item;
			$items = \array_merge($items, $this->getNestedCollection($this->getFilteredCollection(true), $item));
		}
		
		return $items;
	}
}
