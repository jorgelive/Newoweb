<?php

declare(strict_types=1);

namespace App\Finanzas\Enum;

/**
 * Pasarela que procesa el cobro.
 *
 * Se guarda en la fila desde el primer día y NO se deduce de la configuración vigente:
 * las pasarelas conviven en paralelo (no se migra de una a otra), así que un enlace
 * histórico tiene que seguir sabiendo quién lo cobró para poder conciliar. Deducirlo del
 * `.env` de hoy daría cifras falsas el día que cambie el proveedor por defecto.
 *
 * @see \App\Finanzas\Contract\FinPasarelaClientInterface
 */
enum FinPasarela: string
{
    /**
     * Izipay Perú, sobre la plataforma Lyra (`api.micuentaweb.pe`).
     *
     * ⚠️ En esta cuenta **no está habilitado**: Izipay exige S/200 000 de venta acumulada
     * para dar acceso a los enlaces de pago. El conector se mantiene entero y funcionando
     * para el día que cambien la política o nos habiliten; no se borra.
     */
    case IZIPAY = 'izipay';

    /**
     * Culqi (`api.culqi.com/v2`). Sin muro de volumen ni coste de afiliación.
     *
     * Es la pasarela operativa hoy. Cobra tarjeta, Yape, Plin, PagoEfectivo y Cuotéalo BCP.
     */
    case CULQI = 'culqi';

    public function etiqueta(): string
    {
        return match ($this) {
            self::IZIPAY => 'Izipay',
            self::CULQI  => 'Culqi',
        };
    }
}
