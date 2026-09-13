<?php

declare(strict_types=1);

namespace App\Pms\Nombre\Copia;

use App\Contract\Nombre\CopiaDelNombre;
use App\Contract\Nombre\CorreccionDeNombre;
use App\Pms\Entity\PmsEventoCalendario;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

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

    /**
     * ⚠️ **Un UPDATE, no leer-modificar-guardar, y es una decisión de fondo.**
     *
     * La primera versión cargaba los eventos, les cambiaba el título y hacía `flush()`. Tres
     * cosas iban mal con eso, y ninguna se ve leyendo el código:
     *
     *  1. Ese `flush()` corre dentro del `postFlush` de la reserva y **despierta a todos los
     *     listeners**: el de push a Beds24 mete el evento en `eventosTouched` y encola un `POST`
     *     por cada corrección de nombre — por una puerta que nadie diseñó, y justo cuando
     *     `IGNORED_FIELDS_ON_LOCKED_OTA` excluye el nombre del push a propósito.
     *  2. Si ese flush falla a nivel SQL, Doctrine cierra el `EntityManager` y las copias
     *     siguientes revientan con `EntityManagerClosed` — errores que no señalan al culpable.
     *  3. Entre leer el título y escribirlo hay una carrera. Con la condición dentro del `WHERE`
     *     no la hay.
     *
     * 🔑 **El guarda «sólo lo nuestro» va en el `WHERE`**: si el título no coincide con el nombre
     * anterior, lo escribió una persona y esa fila no entra en el `UPDATE`. Atómico y sin leer.
     *
     * ⚠️ Y el UUID va **tipado**. Sin `UuidType::NAME` se compara un texto de 36 caracteres contra
     * un `binary(16)`: no falla, devuelve **cero filas** — que esta interfaz define como respuesta
     * normal, así que el mecanismo entero estaba muerto y su fallo se leía como éxito.
     */
    public function corregir(CorreccionDeNombre $correccion): int
    {
        if ($correccion->origenTipo !== self::ORIGEN) {
            return 0;
        }

        return (int) $this->em->createQuery(
            'UPDATE ' . PmsEventoCalendario::class . ' e
             SET e.tituloCache = :ahora
             WHERE e.reserva = :reserva AND e.tituloCache = :antes'
        )
            ->setParameter('reserva', Uuid::fromString($correccion->origenId), UuidType::NAME)
            ->setParameter('antes', $correccion->completoAntes())
            ->setParameter('ahora', mb_substr($correccion->completoAhora(), 0, 180))
            ->execute();
    }

    /**
     * Los títulos que no dicen lo que dice su reserva.
     *
     * ⚠️ Se compara **sin caja y en los dos órdenes**: las formas que este sistema ha podido
     * escribir ahí son el par cruzado y el par gritado, y a menudo las dos a la vez.
     *
     * 🔥 **El `<>` necesita `BINARY` y sin él esta consulta estaba CIEGA.** Las tres columnas son
     * `utf8mb4_unicode_ci`, así que para MySQL «robin uylenbroeck» **es igual a** «Robin
     * Uylenbroeck» — y también «Jose» a «José». El `<>` descartaba justo los desajustes de sólo
     * caja, que son el caso más común, de modo que el informe decía «todas al día» con títulos
     * podridos dentro. Los `LOWER()` de las dos comparaciones de abajo sobran por lo mismo: bajo
     * `_ci` ya no distinguen, y dejarlos hacía creer que la insensibilidad estaba puesta a mano
     * donde no hacía falta y ausente donde sí.
     */
    public function desincronizadas(): int
    {
        $sql = <<<'SQL'
            SELECT COUNT(*)
            FROM pms_evento_calendario e
            JOIN pms_reserva r ON r.id = e.reserva_id
            WHERE e.titulo_cache IS NOT NULL
              AND TRIM(CONCAT(COALESCE(r.nombre_cliente, ''), ' ', COALESCE(r.apellido_cliente, ''))) <> ''
              AND BINARY e.titulo_cache <> BINARY TRIM(CONCAT(COALESCE(r.nombre_cliente, ''), ' ', COALESCE(r.apellido_cliente, '')))
              AND (
                    e.titulo_cache = TRIM(CONCAT(COALESCE(r.nombre_cliente, ''), ' ', COALESCE(r.apellido_cliente, '')))
                 OR e.titulo_cache = TRIM(CONCAT(COALESCE(r.apellido_cliente, ''), ' ', COALESCE(r.nombre_cliente, '')))
              )
            SQL;

        return (int) $this->em->getConnection()->fetchOne($sql);
    }
}
