<?php
namespace App\Oweb\Entity;

use Doctrine\ORM\Mapping as ORM;
use Gedmo\Translatable\Entity\MappedSuperclass\AbstractPersonalTranslation;

#[ORM\Entity]
#[ORM\Table(
    name: 'mae_tipocontactotranslation',
    uniqueConstraints: [
        new ORM\UniqueConstraint(
            name: 'unique_idx',
            columns: ['locale', 'object_id', 'field']
        ),
    ]
)]
class MaestroTipocontactoTranslation extends AbstractPersonalTranslation
{
    #[ORM\ManyToOne(targetEntity: MaestroTipocontacto::class, inversedBy: 'translations')]
    #[ORM\JoinColumn(name: 'object_id', referencedColumnName: 'id', onDelete: 'CASCADE', nullable: false)]
    protected $object;

    public function __construct(?string $locale = null, ?string $field = null, ?string $content = null)
    {
        parent::__construct($locale, $field, $content);
    }

    public function setObject($object)
    {
        $this->object = $object;
        return $this;
    }
}
