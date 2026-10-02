<?php

declare(strict_types=1);

namespace App\Message\Contract;

use App\Message\Dto\AsuntoSinTelefono;
use DateTimeImmutable;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Los asuntos VIGENTES de un dominio a cuyo huésped hoy no le sale un WhatsApp.
 *
 * El reporte del portal los junta sin saber qué es una reserva ni un expediente: cada dominio
 * decide qué es «vigente» y cómo se llama lo suyo. Incorporar uno nuevo es implementar esto.
 */
#[AutoconfigureTag('app.message.asuntos_sin_telefono')]
interface AsuntosSinTelefonoInterface
{
    /** @return list<AsuntoSinTelefono> */
    public function sinTelefono(DateTimeImmutable $hoy): array;
}
