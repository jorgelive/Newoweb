<?php

declare(strict_types=1);

namespace App\Message\Dto\PlantillaMeta;

use App\Dto\Lee;

/**
 * Una plantilla de WhatsApp TAL COMO LA DEVUELVE META al listarlas
 * (`GET /{wabaId}/message_templates`): un elemento de `data[]`.
 *
 * ⚠️ **Cada elemento es UN idioma, no una plantilla.** Meta devuelve `welcome_booking_command` siete
 * veces, una por idioma, cada una con su `id` —que es lo que hace falta para editarla o borrarla—
 * y su propio `status`. Aquí es igual: una instancia por par nombre + idioma.
 *
 * La leen tres sitios, y antes lo hacían con tres copias de `(string) ($fila['name'] ?? '')`:
 * - {@see \App\Message\Service\Meta\Template\WhatsappMetaTemplateSyncService} (la copia a local),
 * - {@see \App\Message\Service\Meta\Template\WhatsappMetaTemplatePushService} (busca el `id`),
 * - {@see \App\Message\Service\Meta\Template\WhatsappMetaTemplateInventario} («Ver plantillas en Meta»).
 *
 * Ver `docs/Mensajeria.md` §18.
 */
final readonly class PlantillaMeta
{
    /**
     * @param list<ComponenteDePlantillaMeta> $componentes
     */
    public function __construct(
        /** El id de ESTA versión de idioma. */
        public ?string $id,
        /** El «Nombre en Meta»: lo que se empareja con `meta_template_name`. */
        public ?string $nombre,
        /** Código de Meta: `es`, `en`, `pt_BR`… (no el nuestro: `pt`). */
        public ?string $idioma,
        /** `APPROVED`, `PENDING`, `REJECTED`… tal cual, sin normalizar. */
        public ?string $estado,
        /** `UTILITY`, `MARKETING`… */
        public ?string $categoria,
        public array $componentes,
    ) {}

    /**
     * La respuesta ENTERA del listado: `{data: [...], paging: {...}}`. Lo que no sea un objeto
     * dentro de `data` se descarta.
     *
     * @param array<mixed> $respuesta
     * @return list<self>
     */
    public static function listaDesdeRespuesta(array $respuesta): array
    {
        return array_map(self::fromArray(...), Lee::listaDeMapas($respuesta['data'] ?? null));
    }

    /** @param array<mixed> $plantilla */
    public static function fromArray(array $plantilla): self
    {
        // `texto()` en todo: el nombre, el estado y la categoría se guardan en la plantilla local
        // tal cual llegan, y sustituyen a lecturas crudas.
        return new self(
            id: Lee::texto($plantilla['id'] ?? null),
            nombre: Lee::texto($plantilla['name'] ?? null),
            idioma: Lee::texto($plantilla['language'] ?? null),
            estado: Lee::texto($plantilla['status'] ?? null),
            categoria: Lee::texto($plantilla['category'] ?? null),
            componentes: array_map(
                ComponenteDePlantillaMeta::fromArray(...),
                Lee::listaDeMapas($plantilla['components'] ?? null),
            ),
        );
    }

    /**
     * El PRIMER componente de ese tipo, o `null`.
     *
     * El primero y no el último: es lo que hacían los `extract*()` del sincronizador, que salían
     * del bucle al encontrarlo. Meta no manda dos del mismo tipo, pero si algún día lo hiciera el
     * resultado sería el mismo que antes.
     */
    public function componente(string $tipo): ?ComponenteDePlantillaMeta
    {
        foreach ($this->componentes as $componente) {
            if ($componente->esDeTipo($tipo)) {
                return $componente;
            }
        }

        return null;
    }
}
