<?php

declare(strict_types=1);

namespace App\Front\Comun\Service;

use App\Front\Comun\Entity\ExperienciaMedio;
use Liip\ImagineBundle\Imagine\Filter\FilterManager;
use Liip\ImagineBundle\Model\Binary;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Dónde viven las fotos de los álbumes de experiencias y sus tamaños, **fuera de `public/`**.
 *
 * ── Por qué no se usa Liip como en las demás fotos ──────────────────────────
 * Liip genera las miniaturas bajo demanda desde una URL pública que no comprueba nada
 * (`/media/cache/resolve/{filtro}/{ruta}`) y las deja en `public/media/cache/`, que sirve nginx sin
 * pasar por PHP. Para estas fotos eso son dos agujeros:
 *
 *  - quien conozca el nombre del original puede volver a sacar una miniatura **después de que se
 *    revoque la autorización** de las familias: el álbum desaparece de la web, la foto no;
 *  - y si la carpeta privada fuese uno de sus orígenes de datos, por esa misma URL saldría
 *    cualquier imagen de `var/documentos/` —escaneos de pasaporte incluidos—.
 *
 * Así que se usan sus FILTROS —los mismos tamaños que el resto de la web— pero no su caché: los
 * tamaños se generan aquí, se guardan junto al original en la carpeta privada y los entrega
 * `ExperienciaFotoController`, que comprueba el permiso en cada petición. Revocar corta todas las
 * URLs a la vez, sin copias públicas que perseguir. Ver docs/WebPublica.md §2.1.a.
 */
final readonly class FotosDeExperiencia
{
    /** Tamaño de la URL → filtro de Liip. Los mismos que usa la web con el resto de fotos. */
    public const array TAMANOS = [
        'miniatura' => 'travel_galeria',
        'grande' => 'travel_cliente',
        'panel' => 'travel_thumb_admin',
    ];

    /**
     * La carpeta, relativa a `var/documentos/`. Debajo de ella porque es lo que nginx expone como
     * `/_privado/` (`internal;`, sólo por `X-Accel-Redirect`). La misma que el mapeo
     * `front_experiencias` de `config/packages/vich_uploader.yaml`: si cambia una, cambia la otra.
     */
    public const string CARPETA = 'experiencias';

    public function __construct(
        private FilterManager $filtros,
        #[Autowire(param: 'kernel.project_dir')]
        private string $projectDir,
    ) {
    }

    /**
     * La foto en ese tamaño, como ruta relativa a `var/documentos/` (lo que va detrás de
     * `/_privado/`). La genera la primera vez que se pide. `null` si no hay original que reducir.
     */
    public function enTamano(ExperienciaMedio $medio, string $tamano): ?string
    {
        $nombre = $medio->getImageName();
        $filtro = self::TAMANOS[$tamano] ?? null;

        if ($nombre === null || $nombre === '' || $filtro === null) {
            return null;
        }

        $original = $this->base() . '/' . $nombre;
        if (!is_file($original)) {
            return null;
        }

        $relativa = self::CARPETA . '/tamanos/' . $tamano . '/' . pathinfo($nombre, PATHINFO_FILENAME) . '.webp';
        $destino = $this->documentos() . '/' . $relativa;

        if (is_file($destino)) {
            return $relativa;
        }

        $contenido = file_get_contents($original);
        if ($contenido === false) {
            return null;
        }

        $reducida = $this->filtros->applyFilter(
            new Binary($contenido, mime_content_type($original) ?: 'application/octet-stream', pathinfo($nombre, PATHINFO_EXTENSION)),
            $filtro
        );

        if (!is_dir(dirname($destino)) && !mkdir(dirname($destino), 0775, true) && !is_dir(dirname($destino))) {
            return null;
        }

        // A un temporal y luego `rename()`: dos peticiones a la vez por la misma foto no dejan un
        // archivo a medio escribir para la que llega segunda.
        $temporal = $destino . '.' . bin2hex(random_bytes(4)) . '.tmp';
        file_put_contents($temporal, $reducida->getContent());
        rename($temporal, $destino);

        return $relativa;
    }

    /** Borra los tamaños de una foto. El original lo borra Vich (`delete_on_remove`/`_update`). */
    public function borrarTamanos(string $nombre): void
    {
        foreach (array_keys(self::TAMANOS) as $tamano) {
            $ruta = $this->base() . '/tamanos/' . $tamano . '/' . pathinfo($nombre, PATHINFO_FILENAME) . '.webp';
            if (is_file($ruta)) {
                @unlink($ruta);
            }
        }
    }

    private function documentos(): string
    {
        return $this->projectDir . '/var/documentos';
    }

    private function base(): string
    {
        return $this->documentos() . '/' . self::CARPETA;
    }
}
