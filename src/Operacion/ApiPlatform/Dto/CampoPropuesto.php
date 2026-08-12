<?php

declare(strict_types=1);

namespace App\Operacion\ApiPlatform\Dto;

use Symfony\Component\Serializer\Attribute\Groups;

/**
 * Un campo que la cotización quiere cambiar en una fila de La Biblia.
 *
 * La aprobación es POR CAMPO y no por fila a propósito: si la cotización movió la
 * fecha y el operador ya había fijado la hora real y el prestador, aprobar «la fila»
 * no significa nada — ¿acepta la fecha nueva y conserva su hora, o se lleva todo por
 * delante? El conflicto real es campo a campo, y la pantalla tiene que mostrarlo así.
 */
final class CampoPropuesto
{
    public function __construct(
        /** Nombre técnico, el que viaja de vuelta en la aprobación. */
        #[Groups(['operacion:plan:read'])]
        public string $campo = '',

        /** Etiqueta legible para la columna del diff. */
        #[Groups(['operacion:plan:read'])]
        public string $etiqueta = '',

        #[Groups(['operacion:plan:read'])]
        public ?string $valorActual = null,

        #[Groups(['operacion:plan:read'])]
        public ?string $valorPropuesto = null,

        /**
         * El operador había editado este campo a mano. Llega DESMARCADO: entre la
         * cotización y quien habló con el proveedor, gana quien habló con el proveedor
         * mientras nadie diga lo contrario.
         */
        #[Groups(['operacion:plan:read'])]
        public bool $enConflicto = false,
    ) {}
}
