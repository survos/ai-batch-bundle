<?php

declare(strict_types=1);

namespace Tacman\AiBatch\Menu;

use Tacman\AiBatch\Entity\AiBatch;
use Survos\TablerBundle\Event\MenuEvent;
use Survos\TablerBundle\Menu\AbstractAdminMenuSubscriber;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

class AiBatchMenuSubscriber extends AbstractAdminMenuSubscriber
{
    protected function getLabel(): string { return 'AI Batch'; }
    protected function getResourceClasses(): array { return [AiBatch::class]; }

    #[AsEventListener(event: MenuEvent::ADMIN_NAVBAR_MENU)]
    public function onAdminNavbarMenu(MenuEvent $event): void
    {
        $this->buildAdminMenu($event);
    }
}
