<?php

declare(strict_types=1);

namespace Datalist;

use Nette\Application\UI\Control;
use Nette\Utils\Paginator;
use StORM\Collection;
use StORM\ICollection;

/**
 * @property array<callable(static): void> $onAnchor
 * @method onLoad(\StORM\ICollection $source)
 * @method onSaveState(\Datalist\Datalist $param, array $params)
 * @method onLoadState(\Datalist\Datalist $param, array $params)
 */
class Datalist extends Control
{
	/**
	 * @var array<callable(\StORM\ICollection): void> Occurs before data is load
	 */
	public array $onLoad = [];
	
	/**
	 * @var array<callable(static, mixed[]): void> Occurs before state is loaded
	 */
	public array $onLoadState = [];
	
	/**
	 * @var array<callable(static, mixed[]): void> Occurs after state is save
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
	 * @var string[]
	 */
	protected array $secondaryOrder = [];
	
	/**
	 * @var string[]
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
	 * @var mixed[]|null[]
	 */
	protected array $filterDefaultValue = [];
	
	/**
	 * @var string[]
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
	
	protected ICollection $source;
	
	protected ?ICollection $filteredSource = null;
	
	/**
	 * @var \StORM\Entity[]|object[]|null
	 */
	protected ?array $itemsOnPage = null;
	
	/**
	 * @var callable|null
	 */
	protected $nestingCallback = null;
	
	/**
	 * @var callable|null
	 */
	protected $itemCountCallback = null;
	
	/**
	 * @var bool[]
	 */
	private array $statefulFilters = [];
	
	public function __construct(ICollection $source, ?int $defaultOnPage = null, ?string $defaultOrderExpression = null, ?string $defaultOrderDir = null)
	{
		$this->source = $source;
		
		$this->itemCountCallback = function (ICollection $filteredSource) {
			return $filteredSource->count();
		};
		
		if ($defaultOnPage !== null) {
			$this->setDefaultOnPage($defaultOnPage);
		}
		
		if ($defaultOrderExpression !== null) {
			$this->setDefaultOrder($defaultOrderExpression, $defaultOrderDir ?: $this->defaultDirection);
		}
		
		if (!($source instanceof Collection)) {
			return;
		}
		
		foreach ($source->getRepository()->getStructure()->getColumns(true) as $column) {
			if ($column->hasMutations()) {
				$this->allowedOrderColumn[$column->getPropertyName()] = $source->getPrefix(true) . $column->getName() . $source->getConnection()->getMutationSuffix();
				
				foreach (\array_keys($source->getConnection()->getAvailableMutations()) as $suffix) {
					$this->allowedOrderColumn[$column->getPropertyName() . $suffix] = $source->getPrefix(true) . $column->getName() . $suffix;
				}
			} else {
				$this->allowedOrderColumn[$column->getPropertyName()] = $source->getPrefix(true) . $column->getName();
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
	 * @param string[] $orderBy
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
			@[$name, $orderDirection] = \explode('-', $this->order);
			unset($name);
		}
		
		if ($reverse) {
			return $orderDirection === 'ASC' ? 'DESC' : 'ASC';
		}
		
		return $orderDirection;
	}
	
	public function getOrder(): ?string
	{
		if ($this->order === null) {
			return $this->defaultOrder;
		}
		
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
	 * @param string[] $columns
	 * @param bool $merge
	 */
	public function setAllowedOrderColumns(array $columns, bool $merge = false): void
	{
		$this->allowedOrderColumn = $merge ? $this->allowedOrderColumn + $columns : $columns;
	}
	
	public function addOrderExpression(string $name, callable $callback): void
	{
		$this->orderExpressions[$name] = $callback;
	}
	
	/**
	 * @param string[] $listToRemove
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
	 * @param string[] $listToRemove
	 */
	public function removeFilterExpressions(array $listToRemove): void
	{
		foreach ($listToRemove as $name) {
			unset($this->filterExpressions[$name]);
			unset($this->filterDefaultValue[$name]);
		}
	}
	
	/**
	 * @param string[] $list
	 * @param bool $merge
	 */
	public function setAllowedRepositoryFilters(array $list, bool $merge = false): void
	{
		$this->allowedRepositoryFilters = $merge ? $this->allowedRepositoryFilters + $list : $list;
	}
	
	/**
	 * @param mixed[] $filters
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
	 * @return callable[]
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
	 * @param mixed[] $params
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
	 * @param mixed[] $params
	 */
	public function saveState(array &$params): void
	{
		parent::saveState($params);
		
		$this->onSaveState($this, $params);
		
		if ($this->autoCanonicalize) {
			if (isset($params['onpage']) && $this->defaultOnPage !== null && $this->defaultOnPage === (int)$params['onpage']) {
				$params['onpage'] = null;
			}
			
			if (isset($params['order']) && $this->defaultOrder !== null && ($this->defaultOrder . '-' . $this->defaultDirection) === $params['order']) {
				$params['order'] = null;
			}
			
			if (isset($params['page']) && (int)$params['page'] === 1) {
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
	
	public function getSource(bool $newInstance = true): ICollection
	{
		return $newInstance ? clone $this->source : $this->source;
	}
	
	public function getFilteredSource(bool $newInstance = true): ICollection
	{
		if ($this->filteredSource && !$newInstance) {
			return $this->filteredSource;
		}
		
		$filteredSource = $this->getSource();
		
		// FILTER
		foreach ($this->filters as $name => $value) {
			if ($filteredSource instanceof Collection && !isset($this->filterExpressions[$name]) && \in_array($name, $this->allowedRepositoryFilters)) {
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
			
			if (isset($this->allowedOrderColumn[$this->getOrder()])) {
				$filteredSource->orderBy([$this->allowedOrderColumn[$this->getOrder()] => $this->getDirection()]);
			}
			
			$filteredSource->orderBy($this->secondaryOrder);
		}
		
		if ($newInstance) {
			return $filteredSource;
		}
		
		return $this->filteredSource = $filteredSource;
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
			$this->paginator->setItemCount(\call_user_func($this->itemCountCallback, $this->getFilteredSource()));
		}
		
		$this->paginator->setItemsPerPage($this->getOnPage() ?: \intval($this->paginator->getItemCount()));
		
		return $this->paginator;
	}
	
	public function setOutputFilter(?callable $outputFilter): void
	{
		$this->outputFilter = $outputFilter;
	}
	
	/**
	 * @return \StORM\Entity[]|object[]
	 */
	public function getItemsOnPage(): array
	{
		if ($this->itemsOnPage !== null) {
			return $this->itemsOnPage;
		}
		
		$source = $this->getFilteredSource();
		
		if ($this->getOnPage()) {
			$source->setPage($this->getPage(), $this->getOnPage());
		}
		
		$this->onLoad($source);
		
		
		$this->itemsOnPage = $this->nestingCallback && !$this->filters
			? $this->getNestedSource($source, null) : ($this->outputFilter ? \array_map($this->outputFilter, $source->toArray()) : $source->toArray());
		
		return $this->itemsOnPage;
	}
	
	public function setNestingCallback(callable $callback): void
	{
		$this->nestingCallback = $callback;
	}
	
	/**
	 * @param \Datalist\Datalist $datalist
	 * @param mixed[] $params
	 * @param \Nette\Http\SessionSection|mixed[] $section
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
	 * @param mixed[] $params
	 * @param \Nette\Http\SessionSection|mixed[] $section
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
	 * @param \StORM\ICollection $source
	 * @param \StORM\Entity|object|null $parent
	 * @return \StORM\Entity[]|object[]
	 */
	protected function getNestedSource(ICollection $source, ?object $parent): array
	{
		if ($this->nestingCallback === null) {
			throw new \DomainException('Nesting callback is not set');
		}
		
		$items = [];
		\call_user_func_array($this->nestingCallback, [$source, $parent]);
		
		/* @phpstan-ignore-next-line */
		foreach ($source as $key => $item) {
			$items[$key] = $item;
			$items = \array_merge($items, $this->getNestedSource($this->getFilteredSource(true), $item));
		}
		
		return $items;
	}
}
