<?php

declare(strict_types=1);

namespace App\Operacion\ApiPlatform\Dto;

use Symfony\Component\Serializer\Attribute\Groups;

/**
 * Un cambio que la reconciliación PROPONE. Nada se aplica hasta que alguien lo aprueba.
 *
 * `aprobadoPorDefecto` es la política de seguridad hecha dato: crear no destruye nada y
 * llega marcado; borrar y los conflictos llegan desmarcados. Si «actualizar» viniera
 * marcado en un conflicto, esto sería una trampa con aspecto de asistente.
 */
final class CambioPropuesto
{
    public const TIPO_CREAR      = 'crear';
    public const TIPO_ACTUALIZAR = 'actualizar';
    public const TIPO_HUERFANO   = 'huerfano';

    public function __construct(
        /** UUID del componente de la cotización (o de la fila, si es huérfana). */
        #[Groups(['operacion:plan:read'])]
        public string $id = '',

        /** `crear` | `actualizar` | `huerfano` */
        #[Groups(['operacion:plan:read'])]
        public string $tipo = self::TIPO_ACTUALIZAR,

        #[Groups(['operacion:plan:read'])]
        public string $descripcion = '',

        /** El día del itinerario, para ubicar la fila sin salir del panel. */
        #[Groups(['operacion:plan:read'])]
        public ?string $contexto = null,

        #[Groups(['operacion:plan:read'])]
        public ?string $fecha = null,

        /** @var CampoPropuesto[] Vacío en `crear` y en `huerfano`: no hay diff que mostrar. */
        #[Groups(['operacion:plan:read'])]
        public array $campos = [],

        /**
         * Sin casilla: ni siquiera se ofrece. Hoy sólo para borrados de filas que ya
         * viajaron al proveedor o que tienen costo real registrado — perderlas rompería
         * la trazabilidad de lo que se pidió o borraría dinero que no está en ningún
         * otro sitio.
         */
        #[Groups(['operacion:plan:read'])]
        public bool $bloqueado = false,

        /** Por qué está bloqueado o por qué llega desmarcado. Se muestra tal cual. */
        #[Groups(['operacion:plan:read'])]
        public ?string $motivo = null,

        /**
         * La fila pertenece a una Orden de Servicio ya emitida.
         *
         * Se expone aparte de `motivo` porque el panel lo necesita como dato, no como
         * frase: con una OS de por medio **nada** se premarca, ni siquiera los campos
         * sin conflicto — cambiar lo que ya viajó al proveedor se mira entero. Sin este
         * flag el frontend tendría que deducirlo leyendo el texto del motivo.
         */
        #[Groups(['operacion:plan:read'])]
        public bool $enOrdenServicio = false,

        #[Groups(['operacion:plan:read'])]
        public bool $aprobadoPorDefecto = false,
    ) {}
}
