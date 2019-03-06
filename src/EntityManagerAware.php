<?php

declare(strict_types=1);

namespace Linio\Doctrine;

use Doctrine\ORM\EntityManagerInterface;

trait EntityManagerAware
{
    /**
     * @var EntityManagerInterface
     */
    protected $entityManager;

    public function setEntityManager(EntityManagerInterface $entityManager): void
    {
        $this->entityManager = $entityManager;
    }
}
