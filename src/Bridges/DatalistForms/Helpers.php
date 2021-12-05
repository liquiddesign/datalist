<?php

declare(strict_types=1);

namespace Datalist\Bridges\DatalistForms;

use Datalist\Datalist;
use Nette\Forms\Controls\BaseControl;
use Nette\Forms\Controls\Button;
use Nette\InvalidArgumentException;

class Helpers
{
	public function makeFilterForm(\Nette\Application\UI\Form $form): void
	{
		$form->setMethod('get');
		$form->addHidden('filter', 1)->setOmitted(true);
		
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
					$component->setHtmlAttribute('name', "$datalistName-$name");
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
				
				try {
					$component->setDefaultValue($value);
				} catch (InvalidArgumentException $e) {
					// values are out of allowed set catch
				}
			}
		};
	}
}
