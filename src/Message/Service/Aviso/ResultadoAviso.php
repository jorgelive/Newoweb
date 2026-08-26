<?php

declare(strict_types=1);

namespace App\Message\Service\Aviso;

/**
 * Qué pasó al intentar avisar al equipo.
 *
 * Separa **avisados** de **no avisados** porque la diferencia importa río arriba: quien llamó
 * tiene que poder decir la verdad. Prometer que se avisó cuando el aviso se quedó en el sitio
 * es peor que no avisar, y pasa más de lo que parece — mientras una plantilla no está aprobada
 * por Meta, el encolado falla en silencio.
 *
 * `sinDestinatarios` no es un fallo del envío: es que no hay nadie con ese rol y un móvil
 * puesto. Se distingue porque se arregla en otro sitio (el panel de usuarios) y quien avisa
 * debe poder decirlo con esas palabras.
 */
final readonly class ResultadoAviso
{
    /**
     * @param list<string> $avisados   Nombres de quienes sí recibieron el aviso.
     * @param list<string> $noAvisados Nombres de aquellos para los que falló.
     */
    public function __construct(
        public array $avisados = [],
        public array $noAvisados = [],
        public bool $sinDestinatarios = false,
    ) {}

    public function alguienFueAvisado(): bool
    {
        return $this->avisados !== [];
    }
}
