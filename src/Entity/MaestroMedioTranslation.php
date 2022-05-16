<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Gedmo\Translatable\Entity\MappedSuperclass\AbstractPersonalTranslation;

/**
 * @ORM\Entity
 * @ORM\Table(name="mae_mediotranslation",
 *     uniqueConstraints={
 *     @ORM\UniqueConstraint(name="unique_idx", columns={
 *         "locale", "object_id", "field"
 *     })}
 * )
 *
 */
class MaestroMedioTranslation extends AbstractPersonalTranslation
{
    /**
     * @ORM\ManyToOne(targetEntity="MaestroMedio", inversedBy="translations")
     * @ORM\JoinColumn(name="object_id", referencedColumnName="id", onDelete="CASCADE")
     */
    protected $object;
}