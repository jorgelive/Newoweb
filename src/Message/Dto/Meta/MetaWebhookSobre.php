<?php

declare(strict_types=1);

namespace App\Message\Dto\Meta;

use App\Dto\Lee;

/**
 * El sobre entero de un webhook de Meta: `entry[].changes[].value`, ya aplanado.
 *
 * 🔥 **Existe para que el recorrido esté escrito UNA vez.** Lo recorrían a mano el webhook
 * (`MetaWebhookController`) y el «reprocesar» del panel de auditoría
 * (`MetaWebhookAuditCrudController`), copiado línea a línea —su propio comentario pedía extraerlo—.
 * Y lo recorrían sin mirar: `$entry['changes']` sin comprobar que existiera y `$value['contacts'][0]`
 * sin comprobar que la lista trajera algo. Nunca faltaron en 3 250 webhooks reales, pero el día que
 * Meta cambie la forma el fallo saldría aquí, en un solo sitio, y no como un warning suelto a mitad
 * del persister.
 *
 * Ver `docs/Mensajeria.md` — el webhook de Meta y sus DTO.
 */
final readonly class MetaWebhookSobre
{
    /**
     * @param list<MetaCambio> $cambios
     */
    public function __construct(
        /** `whatsapp_business_account` en la práctica. Va a la auditoría. */
        public ?string $objeto,
        public array $cambios,
    ) {}

    /** @param array<mixed> $payload */
    public static function fromArray(array $payload): self
    {
        $cambios = [];

        foreach (Lee::listaDeMapas($payload['entry'] ?? null) as $entrada) {
            foreach (Lee::listaDeMapas($entrada['changes'] ?? null) as $cambio) {
                $cambios[] = MetaCambio::fromArray(Lee::mapa($cambio['value'] ?? null));
            }
        }

        return new self(
            objeto: Lee::texto($payload['object'] ?? null),
            cambios: $cambios,
        );
    }
}
