<?php

declare(strict_types=1);

namespace Datalist\Bridges\DatalistForms;

use Datalist\Datalist;
use Nette\Application\UI\Presenter;
use Nette\Forms\Container;
use Nette\Forms\Controls\BaseControl;
use Nette\Forms\Controls\Button;
use Nette\Forms\Form;
use Nette\InvalidArgumentException;

class Helpers
{
	public const FILTER_KEY = 'filter';
	
	public function makeFilterForm(\Nette\Application\UI\Form $form, bool $filterInput = true, bool $removeSignalKey = false): void
	{
		$form->setMethod($form::GET);
		
		if ($filterInput) {
			$form->addHidden(self::FILTER_KEY, 1)->setOmitted(true);
		}
		
		if ($removeSignalKey) {
			$form->onRender[] = function ($form): void {
				$form->removeComponent($form[Presenter::SIGNAL_KEY]);
			};
		}
		
		$form->onAnchor[] = function (\Nette\Application\UI\Form $form): void {
			/** @var \Datalist\Datalist $datalist */
			$datalist = $form->lookup(Datalist::class);
			$datalistName = $datalist->getName();
			$submit = false;
			
			/** @var \Nette\Forms\Controls\BaseControl $component */
			foreach ($form->getComponents(true, BaseControl::class) as $component) {
				$name = $component->getName();
				$form->getAction()->setParameter("$datalistName-$name", null);
				
				if ($component instanceof Button) {
					if (!$submit) {
						$component->setHtmlAttribute('name', '');
						$submit = true;
					}
				} else {
					if (!$component->getParent() instanceof Form && $component->getParent() instanceof Container) {
						$parentName = $component->getParent()->getName();
						$component->setHtmlAttribute('name', "$datalistName-$parentName" . "[$name]");
					} else {
						$component->setHtmlAttribute('name', "$datalistName-$name");
					}
				}
			}
		};
		
		/* @phpstan-ignore-next-line */
		$form->onRender[] = function (\Nette\Application\UI\Form $form): void {
			/** @var \Datalist\Datalist $datalist */
			$datalist = $form->lookup(Datalist::class);
			
			foreach ($datalist->getFilters() as $filter => $value) {
				/** @var \Nette\Forms\Controls\BaseControl|null $component */
				$component = $form->getComponent($filter, false);
				
				if (!isset($form[$filter]) || !$component || $datalist->getFilterDefaultValue($filter) === $value) {
					continue;
				}
				
				if (!($component instanceof BaseControl)) {
					continue;
				}
				
				try {
					$component->setDefaultValue($value);
				} catch (InvalidArgumentException $e) {
					// values are out of allowed set catch
				}
			}
		};
	}
}
