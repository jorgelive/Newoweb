<?php

declare(strict_types=1);

namespace App\Cotizacion\Documento;

use DateTimeImmutable;

/**
 * Lo que se ha conseguido leer de un trámite migratorio, y **cuánto se puede confiar**.
 *
 * Hermano de {@see DatosDeDocumento}, y a propósito: mismo contrato —todo nulable, `avisos` para lo
 * que no cuadra, un `esUtilizable()` que dice si vale la pena cotejar— para que quien ya entendió
 * uno entienda el otro sin volver a leer nada.
 *
 * ⚠️ **Todo nulable, porque media lectura con los huecos marcados es útil.** Media lectura
 * rellenada a ojo, no. Un E-Ticket fotografiado de una pantalla deja la mitad de los campos fuera
 * de cuadro, y eso tiene que poder decirse.
 */
final readonly class DatosDeEticket
{
    /**
     * @param list<string> $avisos Lo que no cuadra. Vacío NO significa «correcto»: significa «nada
     *        que objetar», que con una lectura automática es lo máximo que se puede decir.
     */
    public function __construct(
        /** El código del propio trámite, el que lleva el QR. */
        public ?string $codigo = null,
        public ?string $nombres = null,
        public ?string $apellidos = null,
        /** El número de pasaporte tal como lo declaró el pasajero AL RELLENAR el trámite. */
        public ?string $pasaporte = null,
        public ?string $nacionalidad = null,
        public ?DateTimeImmutable $fechaEntrada = null,
        public ?string $vueloEntrada = null,
        public ?DateTimeImmutable $fechaSalida = null,
        public ?string $vueloSalida = null,
        /**
         * 🔥 **Las dos mitades son un dato, no una deducción.**
         *
         * El fallo más común no es equivocarse de fecha: es **rellenar sólo la entrada**. Si eso se
         * dedujera de «no leí la fecha de salida», una foto cortada por abajo se contaría como
         * trámite incompleto y mandaría a alguien a rehacer algo que ya estaba bien. Se le pregunta
         * al documento si la sección existe, que es distinto de si se pudo leer.
         */
        public bool $traeEntrada = false,
        public bool $traeSalida = false,
        public array $avisos = [],
        /** El modelo dijo que esto NO es un E-Ticket. Ver {@see self::esOtroDocumento()}. */
        public bool $noEsElTramite = false,
    ) {}

    /**
     * Sin ningún dato de vuelo ni fecha no hay nada que cotejar: lo que hubiera se leyó tan mal que
     * cualquier veredicto sería sobre la lectura, no sobre el trámite.
     */
    /**
     * ¿Lo que se subió es otra cosa —un billete, una tarjeta de embarque— y no el trámite?
     *
     * ⚠️ Es distinto de «no se pudo leer»: aquí se leyó perfectamente y **es otro documento**. Lo
     * que hay que hacer también es distinto —escribirle a esa persona, no mirar el escaneo— y por
     * eso {@see CotejoDeEticket} lo manda a OBSERVADO y no a «no validado».
     */
    public function esOtroDocumento(): bool
    {
        return $this->noEsElTramite;
    }

    public function esUtilizable(): bool
    {
        return $this->fechaEntrada !== null
            || $this->fechaSalida !== null
            || $this->vueloEntrada !== null
            || $this->vueloSalida !== null;
    }
}
