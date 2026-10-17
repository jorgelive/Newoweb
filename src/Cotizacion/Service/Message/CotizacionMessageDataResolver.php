<?php

declare(strict_types=1);

namespace App\Cotizacion\Service\Message;

use App\Cotizacion\Dto\CuentaDelExpediente;
use App\Cotizacion\Entity\CotizacionConversacionEnlace;
use App\Cotizacion\Entity\CotizacionFile;
use App\Message\Contract\MessageDataResolverInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Uid\Uuid;

/**
 * Las variables de plantilla de un mensaje cuyo asunto es un EXPEDIENTE de turismo (16/10/2026).
 *
 * Hasta hoy no existía: el hilo de un expediente no sabía rellenar ninguna plantilla, y por eso
 * Turismo no mandaba ni una. Lo pidió el primer aviso automático —«hemos recibido tu pago»,
 * `CotizacionPagoRecibido`—, que reutiliza la plantilla `pago_recibido` de alojamiento: su texto no
 * habla de reservas y su botón de Meta es `https://pax.openperu.pe/{{1}}`, que admite la ruta del
 * expediente igual que la de la reserva.
 *
 * Sólo las variables que hoy se usan; crecerá con la siguiente plantilla que las necesite:
 *
 * | Variable | Qué es |
 * |---|---|
 * | `guest_name` | el nombre del contacto; si no hay, el pasajero principal o el grupo |
 * | `account_url` | la PROPUESTA que se cobra en pax (`file/{loc}/p/{n}`), donde está «Cómo reservar» con lo pagado y el enlace; sin propuesta que mande, la portada |
 * | `account_path` | lo mismo sin el dominio: la parte variable del botón de Meta |
 *
 * 🔥 Apuntaba a la portada, que no enseña enlaces de pago: el aviso del saldo mandaba a pagar
 * con «el botón de abajo» a una página sin botón (revisión del 16/10/2026).
 */
final readonly class CotizacionMessageDataResolver implements MessageDataResolverInterface
{
    public function __construct(
        private EntityManagerInterface $em,
        #[Autowire('%pax_host_url%')]
        private string $paxHostUrl,
    ) {
    }

    public function supports(string $contextType): bool
    {
        return $contextType === CotizacionConversacionEnlace::CONTEXT_TYPE;
    }

    public function getContextName(string $contextId): ?string
    {
        $file = $this->file($contextId);

        return $file === null ? null : ($file->getNombreGrupo() ?: $file->getPasajeroPrincipal());
    }

    public function getPhoneNumber(string $contextId): ?string
    {
        $file = $this->file($contextId);

        return $file?->getContacto()?->getTelefono() ?: $file?->getTelefono();
    }

    /** Turismo vende directo: no hay channel manager ni OTA que transportar. */
    public function getMetadata(string $contextId): array
    {
        return [];
    }

    public function getMessageVariables(string $contextId, ?string $idioma = null): array
    {
        $file = $this->file($contextId);

        if ($file === null) {
            return [];
        }

        $base = CuentaDelExpediente::de($file)->propuestaBase;
        $ruta = 'file/' . $file->getLocalizador() . ($base !== null ? '/p/' . $base : '');

        return [
            'guest_name' => $file->getContacto()?->getNombre() ?: ($file->getPasajeroPrincipal() ?: $file->getNombreGrupo()),
            'account_url' => rtrim($this->paxHostUrl, '/') . '/' . $ruta,
            'account_path' => $ruta,
        ];
    }

    private function file(string $contextId): ?CotizacionFile
    {
        return Uuid::isValid($contextId) ? $this->em->find(CotizacionFile::class, Uuid::fromString($contextId)) : null;
    }
}
