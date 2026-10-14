<?php

declare(strict_types=1);

namespace Tacman\AiBatch\Menu;

use Survos\MenuBundle\Attribute\AsMenuListener;
use Survos\MenuBundle\Event\MenuEvent;
use Survos\MenuBundle\MenuSlot;
use Survos\TablerBundle\Menu\AbstractAdminMenuSubscriber;
use Tacman\AiBatch\Entity\AiBatch;

class AiBatchMenuSubscriber extends AbstractAdminMenuSubscriber
{
    protected function getLabel(): string
    {
        return 'AI Batch';
    }

    protected function getResourceClasses(): array
    {
        return [AiBatch::class];
    }

    #[AsMenuListener(MenuSlot::ADMIN_NAVBAR_MENU)]
    public function onAdminNavbarMenu(MenuEvent $event): void
    {
        $this->buildAdminMenu($event);
    }
}
