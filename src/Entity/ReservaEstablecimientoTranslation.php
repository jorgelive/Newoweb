<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Gedmo\Translatable\Entity\MappedSuperclass\AbstractPersonalTranslation;


#[ORM\Entity]
#[ORM\Table(
    name: 'res_establecimientotranslation',
    uniqueConstraints: [
        new ORM\UniqueConstraint(
            name: 'unique_idx',
            columns: ['locale', 'object_id', 'field']
        ),
    ]
)]
class ReservaEstablecimientoTranslation extends AbstractPersonalTranslation
{
    #[ORM\ManyToOne(targetEntity: 'ReservaEstablecimiento', inversedBy: 'translations')]
    #[ORM\JoinColumn(name: 'object_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    protected $object;

    public function __construct($locale, $field, $value)
    {
        $this->setLocale($locale);
        $this->setField($field);
        $this->setContent($value);
    }

    /**
     * Set object related
     *
     * @param object $object
     *
     * @return static
     */
    public function setObject($object)
    {
        $this->object = $object;

        return $this;
    }

    /**
     * Get related object
     *
     * @return object
     */
    public function getObject()
    {
        return $this->object;
    }
}