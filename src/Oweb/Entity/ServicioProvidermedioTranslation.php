<?php

namespace App\Oweb\Entity;

use Doctrine\ORM\Mapping as ORM;
use Gedmo\Translatable\Entity\MappedSuperclass\AbstractPersonalTranslation;

#[ORM\Entity]
#[ORM\Table(
    name: 'ser_providermediotranslation',
    uniqueConstraints: [
        new ORM\UniqueConstraint(
            name: 'unique_idx',
            columns: ['locale', 'object_id', 'field']
        ),
    ]
)]
class ServicioProvidermedioTranslation extends AbstractPersonalTranslation
{
    #[ORM\ManyToOne(targetEntity: ServicioProvidermedio::class, inversedBy: 'translations')]
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