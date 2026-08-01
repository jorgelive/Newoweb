<?php

declare(strict_types=1);

namespace App\Pms\ApiPlatform\Dto;

use Symfony\Component\Serializer\Attribute\Groups;

/**
 * Output de POST /pms/pms_tarifa_rangos/generar-masivo.
 *
 * El generador crea N rangos de una sola vez, así que la respuesta no es un
 * recurso sino el resumen de la operación (el equivalente al flash message que
 * mostraba EasyAdmin). El frontend lo usa para el aviso de éxito y para saber
 * si debe refrescar el calendario.
 */
final class PmsTarifaMasivaResult
{
    public function __construct(
        #[Groups(['pms_tarifa_masiva:read'])]
        public int $creadas = 0,

        #[Groups(['pms_tarifa_masiva:read'])]
        public string $mensaje = '',
    ) {}
}
