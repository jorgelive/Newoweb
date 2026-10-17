<?php

declare(strict_types=1);

namespace App\Front\Comun\Controller;

use App\Front\Comun\Entity\ExperienciaMedio;
use App\Front\Comun\Service\FotosDeExperiencia;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * Entrega una foto de un álbum de experiencias **sin que PHP la lea**, y sólo a quien puede verla.
 *
 * Mismo mecanismo que los documentos del expediente (`ArchivoPrivadoController`): PHP comprueba el
 * permiso y responde con `X-Accel-Redirect`; el archivo lo manda nginx desde `var/documentos/`, por
 * la ruta `/_privado/`, que es `internal;`.
 *
 * | Quién | Qué ve |
 * |---|---|
 * | Operador con sesión (host de `util`) | todas, para montar los álbumes |
 * | Cualquiera (la web) | las de un álbum **publicado y autorizado** con dirección |
 * | El resto | 404 — nunca 403: no se confirma que la foto exista |
 *
 * 🔒 **Por qué existe.** Son viajes escolares con menores, y la autorización de las familias se
 * puede retirar. Con las fotos en `public/` la URL era la llave: desaparecía el álbum de la web,
 * pero quien tuviera el enlace —o un buscador que lo hubiera indexado— seguía viendo la foto.
 * Ahora el permiso se mira en cada petición: revocar corta todas las URLs en el acto, como mucho
 * con los cinco minutos que el navegador puede tenerla guardada. Ver docs/WebPublica.md §2.1.a.
 *
 * Registrado en el host del front (`front_comun_controllers`) y en el de `util`
 * (`front_experiencia_foto_util` en `config/routes.yaml`): las sesiones no cruzan de host.
 */
#[AsController]
final class ExperienciaFotoController
{
    /** Lo que un navegador o un intermediario puede guardar una foto publicada. */
    private const int SEGUNDOS_EN_CACHE = 300;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly FotosDeExperiencia $fotos,
        private readonly Security $security,
    ) {
    }

    #[Route(
        '/experiencias/foto/{id}/{tamano}',
        name: 'front_experiencia_foto',
        requirements: ['id' => '[0-9a-f-]{36}', 'tamano' => 'miniatura|grande|panel'],
        methods: ['GET'],
    )]
    public function __invoke(string $id, string $tamano): Response
    {
        $medio = Uuid::isValid($id) ? $this->em->getRepository(ExperienciaMedio::class)->find(Uuid::fromString($id)) : null;

        if (!$medio instanceof ExperienciaMedio || !$medio->esFoto()) {
            return new Response(status: Response::HTTP_NOT_FOUND);
        }

        $publica = self::esPublica($medio);
        if (!$publica && $this->security->getUser() === null) {
            return new Response(status: Response::HTTP_NOT_FOUND);
        }

        $relativa = $this->fotos->enTamano($medio, $tamano);
        if ($relativa === null) {
            return new Response(status: Response::HTTP_NOT_FOUND);
        }

        $respuesta = new Response();
        $respuesta->headers->set('X-Accel-Redirect', '/_privado/' . $relativa);
        // Symfony pondría `text/html`, y esa cabecera gana a la que deduciría nginx: el navegador
        // pintaría la imagen como texto. Los tres tamaños salen en WebP (filtros de Liip).
        $respuesta->headers->set('Content-Type', 'image/webp');

        // Publicada: la puede guardar cualquiera, pero poco rato, para que revocar se note pronto.
        // Sin publicar (sólo la ve el operador): ni intermediarios ni copia sin revalidar.
        $respuesta->headers->set('Cache-Control', $publica ? 'public, max-age=' . self::SEGUNDOS_EN_CACHE : 'private, no-cache');

        return $respuesta;
    }

    /**
     * ¿La puede ver cualquiera? La misma condición que pone la web para enseñar el álbum
     * (`ExperienciaAlbum::esVisibleEnLaWeb()`): publicado, autorizado y con dirección.
     */
    public static function esPublica(ExperienciaMedio $medio): bool
    {
        return $medio->getAlbum()?->esVisibleEnLaWeb() === true;
    }
}
