<?php

namespace App\Oweb\Entity;

use Doctrine\ORM\Mapping as ORM;
use Gedmo\Translatable\Entity\MappedSuperclass\AbstractPersonalTranslation;

#[ORM\Entity]
#[ORM\Table(
    name: 'mae_categoriatourtranslation',
    uniqueConstraints: [
        new ORM\UniqueConstraint(
            name: 'unique_idx'
            , columns: ['locale', 'object_id', 'field']
        ),
    ],
)]
class MaestroCategoriatourTranslation extends AbstractPersonalTranslation
{
    #[ORM\ManyToOne(targetEntity: MaestroCategoriatour::class, inversedBy: 'translations')]
    #[ORM\JoinColumn(name: 'object_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    protected $object;

    public function getObject()
    {
        return $this->object;
    }

    public function setObject($object)
    {
        $this->object = $object;

        return $this;
    }
}