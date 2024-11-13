<?php

/*
 *
 *  ____            _        _   __  __ _                  __  __ ____
 * |  _ \ ___   ___| | _____| |_|  \/  (_)_ __   ___      |  \/  |  _ \
 * | |_) / _ \ / __| |/ / _ \ __| |\/| | | '_ \ / _ \_____| |\/| | |_) |
 * |  __/ (_) | (__|   <  __/ |_| |  | | | | | |  __/_____| |  | |  __/
 * |_|   \___/ \___|_|\_\___|\__|_|  |_|_|_| |_|\___|     |_|  |_|_|
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * @author PocketMine Team
 * @link http://www.pocketmine.net/
 *
 *
 */

declare(strict_types=1);

namespace pocketmine\event;

use pocketmine\plugin\Plugin;
use function array_merge;
use function krsort;
use function spl_object_id;
use const SORT_NUMERIC;

/**
 * @phpstan-template TListener of BaseRegisteredListener
 * @phpstan-template TEvent
 */
abstract class BaseHandlerList{
	/**
	 * @var BaseRegisteredListener[][]
	 * @phpstan-var array<int, array<int, TListener>>
	 */
	private array $handlerSlots = [];

	/**
	 * @var RegisteredListenerCache[]
	 * @phpstan-var array<int, RegisteredListenerCache<TListener>>
	 */
	private array $affectedHandlerCaches = [];

	/**
	 * @phpstan-param class-string<covariant TEvent> $class
	 * @phpstan-param ?static<TListener, TEvent> $parentList
	 * @phpstan-param RegisteredListenerCache<TListener> $handlerCache
	 */
	public function __construct(
		private string $class,
		private ?BaseHandlerList $parentList,
		private RegisteredListenerCache $handlerCache = new RegisteredListenerCache()
	){
		for($list = $this; $list !== null; $list = $list->parentList){
			$list->affectedHandlerCaches[spl_object_id($this->handlerCache)] = $this->handlerCache;
		}
	}

	/**
	 * @phpstan-param TListener $listener
	 */
	public function register(BaseRegisteredListener $listener) : void{
		if(isset($this->handlerSlots[$listener->getPriority()][spl_object_id($listener)])){
			throw new \InvalidArgumentException("This listener is already registered to priority {$listener->getPriority()} of event {$this->class}");
		}
		$this->handlerSlots[$listener->getPriority()][spl_object_id($listener)] = $listener;
		$this->invalidateAffectedCaches();
	}

	/**
	 * @param BaseRegisteredListener[] $listeners
	 * @phpstan-param array<TListener> $listeners
	 */
	public function registerAll(array $listeners) : void{
		foreach($listeners as $listener){
			$this->register($listener);
		}
		$this->invalidateAffectedCaches();
	}

	/**
	 * @phpstan-param TListener|Plugin|Listener $object
	 */
	public function unregister(BaseRegisteredListener|Plugin|Listener $object) : void{
		if($object instanceof Plugin || $object instanceof Listener){
			foreach($this->handlerSlots as $priority => $list){
				foreach($list as $hash => $listener){
					if(($object instanceof Plugin && $listener->getPlugin() === $object)
						|| ($object instanceof Listener && (new \ReflectionFunction($listener->getHandler()))->getClosureThis() === $object) //this doesn't even need to be a listener :D
					){
						unset($this->handlerSlots[$priority][$hash]);
					}
				}
			}
		}else{
			unset($this->handlerSlots[$object->getPriority()][spl_object_id($object)]);
		}
		$this->invalidateAffectedCaches();
	}

	public function clear() : void{
		$this->handlerSlots = [];
		$this->invalidateAffectedCaches();
	}

	/**
	 * @return BaseRegisteredListener[]
	 * @phpstan-return array<int, TListener>
	 */
	public function getListenersByPriority(int $priority) : array{
		return $this->handlerSlots[$priority] ?? [];
	}

	/**
	 * @phpstan-return static<TListener, TEvent>
	 */
	public function getParent() : ?BaseHandlerList{
		return $this->parentList;
	}

	/**
	 * Invalidates all known caches which might be affected by this list's contents.
	 */
	private function invalidateAffectedCaches() : void{
		foreach($this->affectedHandlerCaches as $cache){
			$cache->list = null;
		}
	}

	/**
	 * @param BaseRegisteredListener[] $listeners
	 * @phpstan-param array<int, TListener> $listeners
	 *
	 * @return BaseRegisteredListener[]
	 * @phpstan-return array<int, TListener>
	 */
	abstract protected function sortSamePriorityListeners(array $listeners) : array;

	/**
	 * @return BaseRegisteredListener[]
	 * @phpstan-return list<TListener>
	 */
	public function getListenerList() : array{
		if($this->handlerCache->list !== null){
			return $this->handlerCache->list;
		}

		$handlerLists = [];
		for($currentList = $this; $currentList !== null; $currentList = $currentList->parentList){
			$handlerLists[] = $currentList;
		}

		$listenersByPriority = [];
		foreach($handlerLists as $currentList){
			foreach($currentList->handlerSlots as $priority => $listeners){
				$listenersByPriority[$priority] = array_merge($listenersByPriority[$priority] ?? [], $this->sortSamePriorityListeners($listeners));
			}
		}

		//TODO: why on earth do the priorities have higher values for lower priority?
		krsort($listenersByPriority, SORT_NUMERIC);

		return $this->handlerCache->list = array_merge(...$listenersByPriority);
	}
}
