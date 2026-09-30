<?php

declare(strict_types=1);

namespace ADT\DoctrineComponents\Tests\Fixtures\Listener;

use ADT\DoctrineComponents\BaseListener;
use Doctrine\ORM\Events;

class IncompleteListener extends BaseListener
{
	public function getSubscribedEvents(): array
	{
		return [Events::onFlush, Events::prePersist, Events::postPersist, Events::preUpdate, Events::postUpdate];
	}
}
