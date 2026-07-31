<?php

declare(strict_types=1);

namespace ADT\DoctrineComponents\Tests\Fixtures;

use Tracy\Bar;
use Tracy\IBarPanel;

class RecordingBar extends Bar
{
	/** @var IBarPanel[] */
	public array $addedPanels = [];

	public function addPanel(IBarPanel $panel, ?string $id = null): static
	{
		$this->addedPanels[] = $panel;

		return $this;
	}
}
