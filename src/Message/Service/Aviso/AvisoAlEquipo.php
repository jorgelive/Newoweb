<?php

declare(strict_types=1);

namespace App\Message\Service\Aviso;

use App\Message\Entity\Message;
use Closure;

/**
 * Lo que un dominio quiere que se le diga al equipo por WhatsApp.
 *
 * Es el contrato de {@see AvisoAlEquipoService}, y su forma responde a una regla de este repo:
 * **el servicio no puede saber de qué se avisa**. Aquí no hay un `tipo` que el servicio mire
 * con un `match`; hay un texto ya redactado, una plantilla ya elegida y unas variables ya
 * resueltas. Quien conoce las consecuencias es el dominio, y es él quien las redacta.
 *
 * ### Por qué DOS formas del mismo aviso
 *
 * WhatsApp sólo deja mandar texto libre a quien te escribió en las últimas 24 h. Un operador
 * de guardia no escribe al número del negocio todos los días, así que lo normal es estar
 * FUERA de ventana y necesitar una plantilla aprobada por Meta.
 *
 * Las dos no dicen lo mismo a propósito: el texto libre puede ser multilínea y llevar todo el
 * contexto; la plantilla no, porque **Meta no admite saltos de línea en los parámetros**. Por
 * eso el servicio usa la plantilla SÓLO cuando hace falta — adjuntarla siempre cambiaría el
 * mensaje bueno por el pobre justo en el caso en que no era necesaria.
 */
final readonly class AvisoAlEquipo
{
    /**
     * @param string                    $rol             A quién se avisa. Reciben todos los que lo
     *                                                   tengan Y tengan móvil registrado.
     * @param string                    $texto           El aviso dentro de la ventana de 24 h.
     *                                                   Multilínea si conviene.
     * @param string|null               $plantillaCodigo Código de la plantilla para fuera de
     *                                                   ventana. `null` = sin respaldo: fuera de
     *                                                   ventana ese aviso no saldrá, y se dirá.
     * @param array<string, string>     $variables       Con qué se hidrata la plantilla. Una sola
     *                                                   línea por valor (lo exige Meta).
     * @param array<string, mixed>      $metadata        Rastro que se estampa en el mensaje, para
     *                                                   poder saber después de qué era sin releer
     *                                                   el texto.
     * @param Closure(Message): void|null $ajustarMensaje Última oportunidad del dominio para
     *                                                   marcar el mensaje con algo suyo antes de
     *                                                   persistirlo — el escalado, por ejemplo,
     *                                                   apunta ahí de qué conversación viene.
     *                                                   Existe para que ese dato NO tenga que
     *                                                   entrar en este objeto y contaminar a
     *                                                   todos los demás avisos.
     */
    public function __construct(
        public string $rol,
        public string $texto,
        public ?string $plantillaCodigo = null,
        public array $variables = [],
        public array $metadata = [],
        public ?Closure $ajustarMensaje = null,
    ) {}
}
