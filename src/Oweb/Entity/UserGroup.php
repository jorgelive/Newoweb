<?php

namespace App\Oweb\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * UserGroup
 */
#[ORM\Table(name: 'fos_user_group')]
#[ORM\Entity]
class UserGroup
{

    /**
     * @var integer
     */
    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    protected $id;

    public function getId(): ?int
    {
        return $this->id;
    }

}