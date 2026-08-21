<?php

declare(strict_types=1);

namespace App\Cotizacion\Service\Message;

use App\Contract\MapaDeHitos;
use App\Contract\VinculoComercial;
use App\Cotizacion\Entity\CotizacionConversacionEnlace;
use App\Cotizacion\Entity\CotizacionFile;
use App\Cotizacion\Enum\FileEstadoEnum;
use App\Message\Contract\MessageContextInterface;
use App\Message\Enum\IdentidadTipo;

/**
 * Un EXPEDIENTE de viaje, contado en los términos que entiende la mensajería.
 *
 * ── Por qué no existía y qué faltaba sin él ─────────────────────────────────
 * La tabla `cotizacion_conversacion_enlace` y su proveedor llevaban días en pie, pero **nadie
 * los rellenaba**: no había contexto ni sincronizador, así que ningún expediente llamaba jamás a
 * `MessageConversationFactory::upsertFromContext()`.
 *
 * Consecuencia medible: el teléfono y el correo de un expediente eran **dos columnas y nada
 * más**. Sin hilo, sin identidad y sin asunto. Si esa persona escribía por WhatsApp, no había
 * con qué reconocerla y nacía un walk-in; y no se le podía escribir desde el panel porque su
 * conversación no existía.
 *
 * ── El espejo del PMS, con lo que Turismo sí sabe ───────────────────────────
 * `PmsReservaMessageContext` puede llenar hitos, totales e ítems porque una estancia tiene
 * fechas y cuenta. Un expediente todavía no: sus fechas viven en los servicios de la cotización
 * vigente, y derivarlas aquí sería inventar una regla de negocio en un adaptador.
 *
 * Así que este contexto declara **sólo lo que es cierto hoy** —quién es la persona, por dónde se
 * le reconoce, en qué idioma se le escribe— y deja vacío lo que no sabe. Vacío es una respuesta
 * honesta: `MessageRuleEngine` no programa nada sin hitos, que es exactamente lo que debe pasar
 * mientras Turismo no tenga reglas propias.
 */
final readonly class CotizacionFileMessageContext implements MessageContextInterface
{
    public function __construct(private CotizacionFile $file)
    {
    }

    public function getContextType(): string
    {
        return CotizacionConversacionEnlace::CONTEXT_TYPE;
    }

    public function getContextId(): string
    {
        return (string) $this->file->getId();
    }

    public function getContextLanguage(): string
    {
        return $this->file->getIdiomaCliente();
    }

    public function getContextName(): ?string
    {
        $nombre = trim((string) $this->file->getNombreGrupo());

        return $nombre !== '' ? $nombre : null;
    }

    /**
     * ⚠️ **Normalizado, no formateado.** `getContextPhone()` acaba en
     * `MessageConversation::setGuestPhone()`, que es el destino real de los envíos de WhatsApp y
     * el espejo de la identidad principal.
     *
     * `CotizacionFile::getTelefono()` devuelve `+51 921 166 466` —bonito para pintar— y guardarlo
     * así rompía dos cosas a la vez: dejaba `guestPhone` en desacuerdo con la identidad
     * (`51921166466`), que es justo el descuadre que el panel señala en ámbar; y como
     * `setGuestPhone()` detecta el cambio, **levantaba el veto de WhatsApp** de un número que
     * quizá estaba vetado con razón.
     *
     * Se pasa por el mismo normalizador que usa la identidad. Un solo criterio.
     */
    public function getContextPhone(): ?string
    {
        $telefono = $this->telefono();

        if ($telefono === null) {
            return null;
        }

        $normalizado = IdentidadTipo::TELEFONO->normalizar($telefono);

        return $normalizado !== '' ? $normalizado : null;
    }

    /**
     * Por dónde se reconoce a esta persona.
     *
     * ⚠️ `CotizacionFile::getTelefono()` devuelve el número **formateado** —con espacios y `+`—
     * y aquí da igual: `IdentidadTipo::normalizar()` se queda sólo con los dígitos, así que el
     * valor guardado es el mismo que sembraría el PMS. Se deja pasar a propósito en vez de
     * añadir un getter en crudo: un segundo camino al mismo dato es un sitio más donde
     * divergir.
     *
     * @return array<string, string>
     */
    public function getIdentificadores(): array
    {
        $identificadores = [];

        if (($telefono = $this->telefono()) !== null) {
            $identificadores[IdentidadTipo::TELEFONO->value] = $telefono;
        }

        if (($email = trim((string) $this->file->getEmail())) !== '') {
            $identificadores[IdentidadTipo::EMAIL->value] = $email;
        }

        return $identificadores;
    }

    /** Turismo vende directo: no hay OTA de por medio. */
    public function getOrigin(): string
    {
        return 'directo';
    }

    public function getStatusTag(): string
    {
        return $this->file->getEstado()->value;
    }

    /**
     * Un expediente abierto es alguien a quien se le está vendiendo; cerrado o archivado, ya no.
     *
     * ⚠️ **No se marca `Cliente` desde aquí.** Eso lo dice el pago, no el estado del expediente,
     * y ese dato vive en Finanzas — inventarlo en un adaptador es la clase de regla que después
     * nadie encuentra.
     */
    public function getVinculo(): VinculoComercial
    {
        return $this->file->getEstado() === FileEstadoEnum::ABIERTO
            ? VinculoComercial::Interesado
            : VinculoComercial::Terminado;
    }

    public function getAgencyId(): ?string
    {
        return null;
    }

    /**
     * Vacío mientras Turismo no tenga hitos propios.
     *
     * Las fechas de un viaje viven en los servicios de la cotización vigente, y derivarlas aquí
     * sería escribir una regla de negocio en un adaptador. Sin hitos, `MessageRuleEngine` no
     * programa nada — que es lo correcto hasta que existan reglas de Turismo.
     */
    public function getMilestones(): MapaDeHitos
    {
        return MapaDeHitos::vacio();
    }

    /** @return list<string> */
    public function getItems(): array
    {
        return [];
    }

    public function getFinancialTotal(): ?float
    {
        return null;
    }

    public function isFinancialCleared(): bool
    {
        return false;
    }

    /**
     * Archivado NO es cancelado.
     *
     * `isCancelled()` **cierra la conversación entera**, y un hilo puede llevar además las
     * reservas de esa persona: archivar un expediente no puede silenciar su estancia. Sólo se
     * declara cancelado lo que de verdad terminó mal, y hoy el expediente no distingue eso.
     */
    public function isCancelled(): bool
    {
        return false;
    }

    private function telefono(): ?string
    {
        $telefono = trim((string) $this->file->getTelefono());

        return $telefono !== '' ? $telefono : null;
    }
}
