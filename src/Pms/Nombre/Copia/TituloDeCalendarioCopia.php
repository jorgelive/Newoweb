<?php

declare(strict_types=1);

namespace App\Pms\Nombre\Copia;

use App\Contract\Nombre\CopiaDelNombre;
use App\Contract\Nombre\CorreccionDeNombre;
use App\Pms\Entity\PmsEventoCalendario;
use Doctrine\ORM\EntityManagerInterface;

/**
 * El nombre del huésped que el calendario guarda en `titulo_cache`.
 *
 * No es cosmético: `PmsDisponibilidadService` lo lee **como el nombre del huésped**
 * (`e.titulo_cache AS huesped`). Un caché que nadie invalida no envejece mal: envejece en
 * silencio, y así llegaron a haber 23 títulos de 423 diciendo el nombre viejo.
 */
final readonly class TituloDeCalendarioCopia implements CopiaDelNombre
{
    /** El único origen que le incumbe. Cualquier otro pasa de largo sin tocar la base. */
    private const string ORIGEN = 'pms_reserva';

    public function __construct(private EntityManagerInterface $em) {}

    public function queCopia(): string
    {
        return 'título del calendario';
    }

    public function corregir(CorreccionDeNombre $correccion): int
    {
        if ($correccion->origenTipo !== self::ORIGEN) {
            return 0;
        }

        /** @var list<PmsEventoCalendario> $eventos */
        $eventos = $this->em->createQuery(
            'SELECT e FROM ' . PmsEventoCalendario::class . ' e WHERE e.reserva = :r'
        )->setParameter('r', $correccion->origenId)->getResult();

        $antes = $correccion->completoAntes();
        $tocados = 0;

        foreach ($eventos as $evento) {
            // 🔑 Sólo lo que era nuestro: si no coincide con el nombre anterior, ese título lo
            // escribió una persona y pisarlo sería borrarle el trabajo para arreglar un caché.
            if (trim((string) $evento->getTituloCache()) !== $antes) {
                continue;
            }

            $evento->setTituloCache(mb_substr($correccion->completoAhora(), 0, 180));
            ++$tocados;
        }

        if ($tocados > 0) {
            $this->em->flush();
        }

        return $tocados;
    }

    /**
     * Los títulos que no dicen lo que dice su reserva.
     *
     * ⚠️ Se compara **sin caja y en los dos órdenes**: las dos formas que este sistema ha podido
     * escribir ahí son el par cruzado y el par gritado, y a menudo las dos a la vez. Comparando
     * sólo la forma exacta, el caso más común quedaba fuera del recuento y el informe salía en
     * verde con el fallo dentro.
     */
    public function desincronizadas(): int
    {
        $sql = <<<'SQL'
            SELECT COUNT(*)
            FROM pms_evento_calendario e
            JOIN pms_reserva r ON r.id = e.reserva_id
            WHERE e.titulo_cache IS NOT NULL
              AND TRIM(CONCAT(COALESCE(r.nombre_cliente, ''), ' ', COALESCE(r.apellido_cliente, ''))) <> ''
              AND e.titulo_cache <> TRIM(CONCAT(COALESCE(r.nombre_cliente, ''), ' ', COALESCE(r.apellido_cliente, '')))
              AND (
                    LOWER(e.titulo_cache) = LOWER(TRIM(CONCAT(COALESCE(r.nombre_cliente, ''), ' ', COALESCE(r.apellido_cliente, ''))))
                 OR LOWER(e.titulo_cache) = LOWER(TRIM(CONCAT(COALESCE(r.apellido_cliente, ''), ' ', COALESCE(r.nombre_cliente, ''))))
              )
            SQL;

        return (int) $this->em->getConnection()->fetchOne($sql);
    }
}
