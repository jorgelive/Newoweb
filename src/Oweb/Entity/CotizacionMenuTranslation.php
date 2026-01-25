<?php

namespace App\Oweb\Entity;

use Doctrine\ORM\Mapping as ORM;
use Gedmo\Translatable\Entity\MappedSuperclass\AbstractPersonalTranslation;

/**
 * @extends AbstractPersonalTranslation
 */
#[ORM\Entity]
#[ORM\Table(
    name: 'cot_menu_translation',
    indexes: [
        new ORM\Index(columns: ['locale'], name: 'cot_menu_translation_idx')
    ],
    uniqueConstraints: [
        new ORM\UniqueConstraint(name: 'lookup_unique_idx', columns: ['locale', 'object_id', 'field'])
    ]
)]
class CotizacionMenuTranslation extends AbstractPersonalTranslation
{


    #[ORM\ManyToOne(targetEntity: CotizacionMenu::class, inversedBy: 'translations')]
    #[ORM\JoinColumn(name: 'object_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    protected $object; // NO tipar (según consigna)

    public function setObject($object): void // NO tipar parámetros ni retorno
    {
        $this->object = $object;
    }
}
