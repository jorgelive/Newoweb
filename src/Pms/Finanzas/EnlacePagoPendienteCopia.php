<?php

declare(strict_types=1);

namespace App\Pms\Finanzas;

use App\Contract\Nombre\CopiaDelNombre;
use App\Contract\Nombre\CorreccionDeNombre;
use App\Finanzas\Entity\FinEnlacePago;
use App\Finanzas\Enum\FinEnlacePagoEstado;
use App\Finanzas\Enum\FinOrigenCobro;
use Doctrine\ORM\EntityManagerInterface;

/**
 * El nombre del cliente copiado en un enlace de pago **que todavía no se ha cobrado**.
 *
 * ### Por qué el enlace pagado NO entra aquí, y el pendiente SÍ
 *
 * «El enlace congela el nombre porque es lo que se mandó a la pasarela» es cierto **después** de
 * pagar. Antes no se ha mandado nada: `CulqiClient` e `IzipayClient` leen estos dos campos **en el
 * momento del cobro**, de la fila. Un enlace pendiente con el par cruzado le mandará el par
 * cruzado a la pasarela el día que el huésped pague — y hasta entonces se lo enseña en la pantalla
 * de pago.
 *
 * 🔥 **Y la ventana no es teórica: es la normal.** El enlace de adelanto lo emite
 * `PmsInformacionFinancieraCoherenciaListener::emitirPrepagos()` en cuanto llegan los cargos del
 * webhook, casi siempre dentro de los dos segundos en que el corrector del nombre todavía no ha
 * corrido. Es exactamente el enlace que recibe el huésped. Pasó el 12/09/2026 con `KCZVP2`: el
 * enlace nació a las 18:05:59 con «uylenbroeck / robin» y el corrector arregló la reserva a las
 * 18:06:01.
 *
 * ⚠️ **Pagado, fallido, expirado, anulado y reembolsado se quedan como están.** Ésos sí son
 * constancia de una transacción con un titular concreto, y reescribirlos sería falsificar el
 * pasado.
 *
 * ⚠️ **Vive en `src/Pms/` a propósito.** Es el mismo sitio que `PmsReservaOrigenCobroResolver`, que
 * ya conoce los dos lados. Ponerlo en `src/Finanzas/` obligaría a Finanzas a saber qué es una
 * `pms_reserva`, que es justo lo que `origenTipo` existe para evitar.
 */
final readonly class EnlacePagoPendienteCopia implements CopiaDelNombre
{
    public function __construct(private EntityManagerInterface $em) {}

    public function queCopia(): string
    {
        return 'enlaces de pago sin cobrar';
    }

    /**
     * ⚠️ **Compara campo a campo, no el nombre junto.** Aquí hay dos columnas separadas, así que
     * «Ana María / Pérez» → «Ana / María Pérez» es un cambio real en las dos aunque el concatenado
     * sea idéntico. Usar `completoAntes()` —que es una noción de *título*— se lo saltaría.
     */
    public function corregir(CorreccionDeNombre $correccion): int
    {
        if ($correccion->origenTipo !== FinOrigenCobro::PMS_RESERVA->value) {
            return 0;
        }

        return (int) $this->em->createQuery(
            'UPDATE ' . FinEnlacePago::class . ' l
             SET l.clienteNombre = :nombreAhora, l.clienteApellido = :apellidoAhora
             WHERE l.origenTipo = :tipo AND l.origenId = :id
               AND l.estado = :pendiente AND l.pagadoEn IS NULL
               AND l.clienteNombre = :nombreAntes AND l.clienteApellido = :apellidoAntes'
        )
            ->setParameter('tipo', $correccion->origenTipo)
            ->setParameter('id', $correccion->origenId)
            ->setParameter('pendiente', FinEnlacePagoEstado::PENDIENTE->value)
            ->setParameter('nombreAntes', $correccion->nombreAntes)
            ->setParameter('apellidoAntes', $correccion->apellidoAntes)
            ->setParameter('nombreAhora', $correccion->nombreAhora)
            ->setParameter('apellidoAhora', $correccion->apellidoAhora)
            ->execute();
    }

    /** Enlaces sin cobrar cuyo titular ya no es el que dice su reserva. `BINARY` por la collation. */
    public function desincronizadas(): int
    {
        $sql = <<<'SQL'
            SELECT COUNT(*)
            FROM fin_enlace_pago l
            JOIN pms_reserva r ON r.id = UNHEX(REPLACE(l.origen_id, '-', ''))
            WHERE l.origen_tipo = 'pms_reserva'
              AND l.estado = 'pendiente'
              AND l.pagado_en IS NULL
              AND (
                    BINARY COALESCE(l.cliente_nombre, '') <> BINARY COALESCE(r.nombre_cliente, '')
                 OR BINARY COALESCE(l.cliente_apellido, '') <> BINARY COALESCE(r.apellido_cliente, '')
              )
            SQL;

        return (int) $this->em->getConnection()->fetchOne($sql);
    }
}
