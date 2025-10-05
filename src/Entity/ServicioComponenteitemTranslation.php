<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Gedmo\Translatable\Entity\MappedSuperclass\AbstractPersonalTranslation;

#[ORM\Entity]
#[ORM\Table(
    name: 'ser_componenteitemtranslation',
    uniqueConstraints: [
        new ORM\UniqueConstraint(
            name: 'unique_idx',
            columns: ['locale', 'object_id', 'field']),
    ],
)]
class ServicioComponenteitemTranslation extends AbstractPersonalTranslation
{
    #[ORM\ManyToOne(targetEntity: 'ServicioComponenteitem', inversedBy: 'translations')]
    #[ORM\JoinColumn(name: 'object_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    protected $object;

    public function __construct($locale, $field, $value)
    {
        $this->setLocale($locale);
        $this->setField($field);
        $this->setContent($value);
    }

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