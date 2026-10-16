<?php

declare(strict_types=1);

namespace App\Front\Comun\Service;

use App\Front\Comun\Dto\AlbumWeb;
use App\Front\Comun\Dto\MedioWeb;
use App\Front\Comun\Entity\ExperienciaAlbum;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Lo que la web puede enseñar de los álbumes de experiencias: SÓLO los publicados y autorizados
 * con dirección (`ExperienciaAlbum::esVisibleEnLaWeb()`). La condición va en la consulta y no
 * sólo en la plantilla: un álbum sin autorización de las familias no llega ni a cargarse.
 *
 * Los textos salen en el idioma de la página con la misma caída que el catálogo
 * (`TextoI18n::en()`: idioma → español → el primero con texto).
 */
final class ExperienciaWebLector
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /** @return list<AlbumWeb> En el orden del operador; a igualdad, el viaje más reciente primero. */
    public function publicados(string $idioma): array
    {
        /** @var list<ExperienciaAlbum> $albumes */
        $albumes = $this->em->createQuery(<<<'DQL'
            SELECT a, m FROM App\Front\Comun\Entity\ExperienciaAlbum a
            LEFT JOIN a.medios m
            WHERE a.publicado = true AND a.autorizado = true AND a.slug IS NOT NULL
            ORDER BY a.orden ASC, a.fecha DESC, a.createdAt DESC, m.orden ASC
        DQL)->getResult();

        return array_map(fn (ExperienciaAlbum $a): AlbumWeb => $this->aWeb($a, $idioma), $albumes);
    }

    public function album(string $slug, string $idioma): ?AlbumWeb
    {
        $album = $this->em->getRepository(ExperienciaAlbum::class)->findOneBy(['slug' => $slug]);

        return $album instanceof ExperienciaAlbum && $album->esVisibleEnLaWeb() ? $this->aWeb($album, $idioma) : null;
    }

    /** Para el menú: el enlace «Experiencias» sólo sale si hay algo que enseñar. */
    public function hayPublicados(): bool
    {
        return (int) $this->em->createQuery(<<<'DQL'
            SELECT COUNT(a.id) FROM App\Front\Comun\Entity\ExperienciaAlbum a
            WHERE a.publicado = true AND a.autorizado = true AND a.slug IS NOT NULL
        DQL)->getSingleScalarResult() > 0;
    }

    private function aWeb(ExperienciaAlbum $album, string $idioma): AlbumWeb
    {
        $medios = [];
        foreach ($album->getMedios() as $medio) {
            $video = $medio->video();
            if ($medio->esFoto() ? $medio->rutaDeLaFoto() === null : $video === null) {
                continue; // una foto sin archivo o un enlace que ya no se sabe leer no se pintan
            }
            $medios[] = new MedioWeb(
                tipo: $medio->esFoto() ? 'foto' : 'video',
                ruta: $medio->rutaDeLaFoto(),
                pie: TextoI18n::en($medio->getPie(), $idioma),
                video: $video,
            );
        }

        return new AlbumWeb(
            slug: (string) $album->getSlug(),
            titulo: TextoI18n::en($album->getTitulo(), $idioma) ?? (string) $album,
            texto: TextoI18n::en($album->getTexto(), $idioma),
            destino: $album->getDestino(),
            fecha: $album->getFecha(),
            portada: $album->portada()?->rutaDeLaFoto(),
            medios: $medios,
        );
    }
}
