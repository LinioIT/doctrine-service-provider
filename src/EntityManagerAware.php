<?php

namespace Linio\Doctrine;

use Doctrine\ORM\EntityManagerInterface;

trait EntityManagerAware
{
    /**
     * @var EntityManagerInterface
     */
    protected $entityManager;

    /**
     * @param EntityManagerInterface $entityManager
     */
    public function setEntityManager(EntityManagerInterface $entityManager)
    {
        $this->entityManager = $entityManager;
    }
}
