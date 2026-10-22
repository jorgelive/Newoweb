<?php

declare(strict_types=1);

namespace App\Agent\Access;

/**
 * Uno de los asuntos de los que puede hablar el actor en ESTE hilo (`ActorInterface::asuntosDelHilo()`).
 *
 * El modelo lo nombra por su CLAVE (`R1`, `R2`…), que sólo existe para este hilo y este turno: no
 * es un identificador que pueda inventarse ni copiar de otra persona. Elegir por clave es elegir de
 * una lista blanca por construcción (CLAUDE.md: «lo que decide el modelo, valídalo con código»).
 *
 * La etiqueta la redacta el dominio (`ConversacionEnlaceInterface::getEtiqueta()`): casita y
 * fechas, sin localizador ni importes. Ver `docs/Mensajeria.md` §11, «Varias reservas en un hilo».
 */
final readonly class AsuntoDelHilo
{
    public function __construct(
        public string $clave,
        public string $contextType,
        public string $contextId,
        public string $etiqueta,
        /** El que se usa cuando el modelo no dice otro (`EnlacesDeConversacion::asuntoEnCurso()`). */
        public bool $porDefecto,
    ) {
    }
}
