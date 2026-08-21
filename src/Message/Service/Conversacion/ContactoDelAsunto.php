<?php

declare(strict_types=1);

namespace App\Message\Service\Conversacion;

use App\Message\Contract\ProveedorDeContextoInterface;
use App\Message\Entity\MessageIdentidad;
use App\Message\Enum\IdentidadTipo;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * A qué teléfono y a qué correo se le escribe a un asunto — sea del dominio que sea.
 *
 * ── La regla, en un solo sitio ──────────────────────────────────────────────
 * El teléfono y el correo que guarda un asunto —una reserva, un expediente, una organización—
 * son una **semilla**: lo que alguien tecleó al darlo de alta, y con lo que se creó la identidad
 * la primera vez. A partir de ahí dejan de ser la verdad. El dato bueno vive en las identidades
 * de la persona, que es donde se puede corregir, retirar, vetar y marcar cuál se usa.
 *
 * Y son datos que se contradicen a la primera: el cliente da un número mal, se corrige en el
 * editor, y el expediente se queda con el viejo para siempre. Con dos asuntos de la misma
 * persona, el conflicto se multiplica.
 *
 * ── Por qué es genérico y no uno por dominio ────────────────────────────────
 * Porque es la misma regla. `TelefonoDeContacto` la resolvía sólo para el PMS, y copiarla para
 * Turismo y para el catálogo de proveedores habría dado tres versiones que envejecen por
 * separado — la tercera es siempre la que se olvida de mirar si la identidad está vetada.
 *
 * Aquí el dominio sólo aporta dos cosas, y las dos ya estaban:
 *
 *   - el HILO del asunto, vía {@see EnlacesDeConversacion::hiloTitularDe()};
 *   - la SEMILLA, vía {@see ProveedorDeContextoInterface}, que devuelve el contexto del asunto
 *     con sus `getIdentificadores()`.
 *
 * Un dominio nuevo que implemente el contrato de contexto entra sin tocar esto.
 *
 * ── El respaldo, y por qué existe ───────────────────────────────────────────
 * Si el asunto todavía no tiene hilo —recién creado, o nunca se le escribió— no hay identidad de
 * la que tirar y se devuelve la semilla, marcada como tal. Es exactamente lo que se usaba antes
 * de todo esto: el peor caso es el comportamiento de siempre.
 */
final readonly class ContactoDelAsunto
{
    /** @param iterable<ProveedorDeContextoInterface> $proveedores */
    public function __construct(
        private EnlacesDeConversacion $enlaces,
        #[AutowireIterator('app.message.proveedor_contexto')]
        private iterable $proveedores,
    ) {
    }

    /**
     * El contacto de este asunto, con el ORIGEN de cada dato.
     *
     * ⚠️ El origen no se puede deducir comparando valores: lo normal es que la identidad y la
     * semilla coincidan —aquélla se sembró de ésta—, así que compararlas diría «semilla» justo
     * cuando sí hay identidad. Lo tiene que contestar quien lo resolvió.
     *
     * @return array{
     *     telefono: ?string, telefonoOrigen: ?string,
     *     correo: ?string, correoOrigen: ?string,
     *     conversacionId: ?string
     * }
     */
    public function para(string $contextType, string $contextId): array
    {
        $hilo = $this->enlaces->hiloTitularDe($contextType, $contextId);

        $telefono = $this->vivo($hilo?->getTelefonoPrincipal(), IdentidadTipo::TELEFONO);
        $correo = $this->vivo($hilo?->getCorreoPrincipal(), IdentidadTipo::EMAIL);

        // Las semillas sólo se piden si hacen falta: armar el contexto de un dominio va a la
        // base, y con hilo resuelto no aporta nada.
        $semillas = ($telefono === null || $correo === null)
            ? $this->semillasDe($contextType, $contextId)
            : [];

        return [
            'telefono' => $telefono?->getValor() ?? ($semillas[IdentidadTipo::TELEFONO->value] ?? null),
            'telefonoOrigen' => $this->origen($telefono, $semillas[IdentidadTipo::TELEFONO->value] ?? null),
            'correo' => $correo?->getValor() ?? ($semillas[IdentidadTipo::EMAIL->value] ?? null),
            'correoOrigen' => $this->origen($correo, $semillas[IdentidadTipo::EMAIL->value] ?? null),
            'conversacionId' => $hilo?->getId()?->toRfc4122(),
        ];
    }

    /**
     * La identidad sólo cuenta si de verdad se le puede escribir.
     *
     * Vetada o retirada no es «el dato de contacto»: es el que NO hay que usar. Se prefiere caer
     * a la semilla —que al menos es un dato— antes que ofrecer un número muerto.
     */
    private function vivo(?MessageIdentidad $identidad, IdentidadTipo $tipo): ?MessageIdentidad
    {
        return $identidad !== null
            && $identidad->getTipo() === $tipo
            && $identidad->estaViva()
            && !$identidad->isBloqueado()
                ? $identidad
                : null;
    }

    private function origen(?MessageIdentidad $identidad, ?string $semilla): ?string
    {
        if ($identidad !== null) {
            return 'identidad';
        }

        return $semilla !== null ? 'semilla' : null;
    }

    /**
     * Lo que el dominio guarda en el propio asunto.
     *
     * @return array<string, string>
     */
    private function semillasDe(string $contextType, string $contextId): array
    {
        foreach ($this->proveedores as $proveedor) {
            if ($proveedor->supports($contextType)) {
                return $proveedor->para($contextId)?->getIdentificadores() ?? [];
            }
        }

        return [];
    }
}
