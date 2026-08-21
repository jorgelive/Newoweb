<?php

declare(strict_types=1);

namespace App\Travel\Service\Message;

use App\Contract\MapaDeHitos;
use App\Contract\VinculoComercial;
use App\Message\Contract\MessageContextInterface;
use App\Message\Enum\IdentidadTipo;
use App\Travel\Entity\TravelOrganizacion;

/**
 * Una organización proveedora, vista como alguien a quien se le puede escribir.
 *
 * ── Por qué un proveedor no es un cliente ───────────────────────────────────
 * Los dos contextos que existían —una reserva, un expediente— son **asuntos**: algo que empieza,
 * termina, se cobra y se cancela. Una organización no. Es un interlocutor permanente: el hotel
 * al que se le pregunta por una habitación, la agencia que opera un tour. No hay hitos que
 * programar ni dinero que conciliar, y por eso casi todo el contrato devuelve vacío — y eso es
 * la respuesta correcta, no un hueco por rellenar:
 *
 * ```
 * getMilestones()      vacío → no hay reglas automáticas que programar. Se le escribe a mano.
 * getItems()           vacío → no hay casitas ni servicios que listar
 * getFinancialTotal()  null  → lo que se le deba vive en el expediente, no en la organización
 * isCancelled()        false → una organización no se cancela; deja de usarse
 * ```
 *
 * ⚠️ **El vínculo es `Cliente`, y no es un error de nombre.** El enum dice «hay un contexto vivo
 * detrás y se le atiende», que es exactamente la situación: un proveedor con el que trabajamos.
 * `Interesado` abriría las reglas de venta sobre alguien a quien no le vendemos nada.
 *
 * ⚠️ **Beds24 se apaga solo.** No hay resolver para este `contextType`, así que la metadata sale
 * vacía, `es_plataforma` no viene, y tanto `MessageFactory` como `Beds24SendEnqueuer` lo
 * descartan. No hace falta desactivar nada: ver la tabla de claves en
 * {@see \App\Message\Contract\MessageDataResolverInterface::getMetadata()}.
 */
final readonly class TravelOrganizacionMessageContext implements MessageContextInterface
{
    public const string CONTEXT_TYPE = 'travel_organizacion';

    public function __construct(private TravelOrganizacion $organizacion)
    {
    }

    public function getContextType(): string
    {
        return self::CONTEXT_TYPE;
    }

    public function getContextId(): string
    {
        return (string) $this->organizacion->getId();
    }

    /**
     * Español: son proveedores locales, y el catálogo no guarda su idioma.
     *
     * Es una suposición, no un dato, y por eso el hilo la deja **sin fijar**: el primer mensaje
     * que llegue en otro idioma la corrige. Poner `en` —el defecto del PMS, pensado para
     * huéspedes extranjeros— sería peor: aquí acierta menos.
     */
    public function getContextLanguage(): string
    {
        return 'es';
    }

    public function getContextName(): ?string
    {
        $nombre = trim((string) ($this->organizacion->getNombreComercial() ?? $this->organizacion->getRazonSocial()));

        return $nombre !== '' ? $nombre : null;
    }

    public function getContextPhone(): ?string
    {
        $telefono = IdentidadTipo::TELEFONO->normalizar((string) $this->organizacion->getTelefono());

        return $telefono !== '' ? $telefono : null;
    }

    /**
     * @return array<string, string>
     */
    public function getIdentificadores(): array
    {
        $identificadores = [];

        if (($telefono = $this->getContextPhone()) !== null) {
            $identificadores[IdentidadTipo::TELEFONO->value] = $telefono;
        }

        if (($correo = trim((string) $this->organizacion->getEmail())) !== '') {
            $identificadores[IdentidadTipo::EMAIL->value] = $correo;
        }

        return $identificadores;
    }

    /** Nuestro: no hay plataforma que se interponga entre un proveedor y nosotros. */
    public function getOrigin(): string
    {
        return 'directo';
    }

    public function getStatusTag(): string
    {
        return 'proveedor';
    }

    public function getVinculo(): VinculoComercial
    {
        return VinculoComercial::Cliente;
    }

    /** El catálogo no modela mayoristas sobre la organización; devolver null desactiva el filtro. */
    public function getAgencyId(): ?string
    {
        return null;
    }

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
        return true;
    }

    public function isCancelled(): bool
    {
        return false;
    }
}
