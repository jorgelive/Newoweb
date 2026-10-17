<?php

declare(strict_types=1);

namespace App\Front\Comun\EventListener;

use App\Front\Comun\Entity\ExperienciaMedio;
use App\Front\Comun\Service\FotosDeExperiencia;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Doctrine\ORM\Events;

/**
 * Al borrar o reemplazar una foto de álbum, borra también sus tamaños.
 *
 * El original lo borra Vich (`delete_on_remove`, `delete_on_update`); los tamaños los genera
 * {@see FotosDeExperiencia} junto a él, en la carpeta privada, y nadie más los toca. «Borrada»
 * tiene que significar borrada: son viajes escolares con menores.
 *
 * Retirar un álbum (desmarcar «autorizado» o «publicado») NO necesita nada aquí: los tamaños son
 * privados y `ExperienciaFotoController` deja de entregarlos en el acto. Hasta el 17/10/2026
 * había que borrar a mano las copias públicas de Liip, porque nginx las servía sin preguntar.
 */
#[AsEntityListener(event: Events::preUpdate, method: 'preUpdate', entity: ExperienciaMedio::class)]
#[AsEntityListener(event: Events::preRemove, method: 'preRemove', entity: ExperienciaMedio::class)]
final readonly class ExperienciaMedioTamanosListener
{
    public function __construct(private FotosDeExperiencia $fotos)
    {
    }

    public function preUpdate(ExperienciaMedio $medio, PreUpdateEventArgs $args): void
    {
        if (!$args->hasChangedField('imageName')) {
            return;
        }

        $anterior = $args->getOldValue('imageName');
        if (is_string($anterior) && $anterior !== '') {
            $this->fotos->borrarTamanos($anterior);
        }
    }

    public function preRemove(ExperienciaMedio $medio): void
    {
        $nombre = $medio->getImageName();
        if ($nombre !== null && $nombre !== '') {
            $this->fotos->borrarTamanos($nombre);
        }
    }
}
