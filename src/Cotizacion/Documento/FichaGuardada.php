<?php

declare(strict_types=1);

namespace App\Cotizacion\Documento;

use App\Cotizacion\Entity\CotizacionFilepasajero;
use App\Cotizacion\Entity\CotizacionPasajeroIdentificacion;
use DateTimeImmutable;

/**
 * Lo que el manifiesto ya dice de una persona, en primitivas.
 *
 * ⚠️ **En primitivas y no la entidad, a propósito.** El cotejo es la única parte del proceso que
 * decide algo, y aquí no hay base de datos en los tests: pasarle entidades de Doctrine lo dejaría
 * fuera de la suite, que es justo donde tiene que estar. Quien traduzca entidad → esto es el
 * servicio, en un solo sitio.
 */
final readonly class FichaGuardada
{
    public function __construct(
        public ?string $numero = null,
        public ?string $tipo = null,
        public ?DateTimeImmutable $vencimiento = null,
        public ?string $nombreCompleto = null,
        public ?DateTimeImmutable $nacimiento = null,
        /** ISO-2, que es la CLAVE de `MaestroPais` — no un nombre de país. */
        public ?string $nacionalidad = null,
    ) {}

    /**
     * Entidad → primitivas, **en un solo sitio**.
     *
     * ⚠️ Toma la identificación CONCRETA que se está validando, no «la primera que tenga». Con DNI
     * y pasaporte a la vez, cotejar el pasaporte contra el número del DNI daría «número no
     * coincide» siempre: un aviso perfectamente falso repetido en medio manifiesto.
     */
    public static function de(CotizacionFilepasajero $pasajero, CotizacionPasajeroIdentificacion $identificacion): self
    {
        $vencimiento = $identificacion->getVencimiento();
        $nacimiento = $pasajero->getFechanacimiento();

        return new self(
            numero: $identificacion->getNumero(),
            tipo: $identificacion->getTipo()?->value,
            vencimiento: $vencimiento !== null ? DateTimeImmutable::createFromInterface($vencimiento) : null,
            nombreCompleto: trim(($pasajero->getNombre() ?? '') . ' ' . ($pasajero->getApellido() ?? '')),
            nacimiento: $nacimiento !== null ? DateTimeImmutable::createFromInterface($nacimiento) : null,
            // El id de `MaestroPais` ES el ISO-2, así que de este lado no hay nada que traducir.
            nacionalidad: $pasajero->getPais()?->getId(),
        );
    }

    /** Sin número guardado no hay nada contra lo que cotejar: la ficha está a medias. */
    public function tieneNumero(): bool
    {
        return $this->numero !== null && trim($this->numero) !== '';
    }

    /**
     * El cotejo sin MRZ exige número **y** nombre. Sólo el número no basta: un número tecleado
     * igual en dos fichas de la misma familia es justo el error que se busca.
     */
    public function tieneNombre(): bool
    {
        return $this->nombreCompleto !== null && trim($this->nombreCompleto) !== '';
    }
}
