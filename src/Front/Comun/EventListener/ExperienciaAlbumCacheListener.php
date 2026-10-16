<?php

declare(strict_types=1);

namespace App\Front\Comun\EventListener;

use App\Front\Comun\Entity\ExperienciaAlbum;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Doctrine\ORM\Events;
use Liip\ImagineBundle\Imagine\Cache\CacheManager;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Al **retirar** un álbum —desmarcar «autorizado» o «publicado»— borra de la caché de Liip las
 * miniaturas de todas sus fotos.
 *
 * 🔒 El caso que lo pide: un padre retira la autorización. El álbum desaparece de `/experiencias`
 * en el acto, pero las copias que Liip había dejado en `public/media/cache/…` seguían ahí,
 * servidas por nginx sin pasar por PHP. Quien tuviera una de esas URLs —o un buscador que la
 * hubiera indexado— seguía viendo la foto.
 *
 * ⚠️ **Esto NO hace privado el original.** La foto de partida vive en `public/carga/front/…` y
 * su URL sigue respondiendo mientras el archivo exista; lo único que la protege es que el nombre
 * lleva un token aleatorio de 8 bytes ({@see \App\Panel\Entity\Trait\MediaTrait::initializeToken()}),
 * así que no se adivina, pero un enlace ya repartido no se invalida. Cerrarlo del todo exige
 * sacar estas fotos de `public/` y servirlas por controlador con `X-Accel-Redirect`, como los
 * archivos de expediente (ver `cotizacion_file_archivos` en `config/packages/vich_uploader.yaml`),
 * y eso cambia también cómo las pinta la web. Decisión pendiente, anotada en `docs/WebPublica.md`.
 *
 * El decidir y el actuar van en dos eventos a propósito: el cambio sólo se conoce en `preUpdate`
 * (`hasChangedField`), y recorrer la colección de medios —que puede estar sin cargar— es más
 * seguro en `postUpdate`.
 */
#[AsEntityListener(event: Events::preUpdate, method: 'preUpdate', entity: ExperienciaAlbum::class)]
#[AsEntityListener(event: Events::postUpdate, method: 'postUpdate', entity: ExperienciaAlbum::class)]
class ExperienciaAlbumCacheListener
{
    /** @var array<int, true> Los álbumes que se acaban de retirar, por `spl_object_id`. */
    private array $retirados = [];

    public function __construct(
        private readonly CacheManager $cacheManager,
        #[Autowire(param: 'front.path.experiencias')] private readonly string $path,
    ) {
    }

    public function preUpdate(ExperienciaAlbum $album, PreUpdateEventArgs $args): void
    {
        foreach (['autorizado', 'publicado'] as $campo) {
            if ($args->hasChangedField($campo) && $args->getNewValue($campo) === false) {
                $this->retirados[spl_object_id($album)] = true;
            }
        }
    }

    public function postUpdate(ExperienciaAlbum $album, PostUpdateEventArgs $args): void
    {
        if (!isset($this->retirados[spl_object_id($album)])) {
            return;
        }

        unset($this->retirados[spl_object_id($album)]);

        foreach ($album->getMedios() as $medio) {
            $nombre = $medio->getImageName();

            if ($nombre !== null && $nombre !== '') {
                $this->cacheManager->remove(ltrim(rtrim($this->path, '/') . '/' . $nombre, '/'));
            }
        }
    }
}
