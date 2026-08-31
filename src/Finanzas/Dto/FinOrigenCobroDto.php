<?php

declare(strict_types=1);

namespace App\Finanzas\Dto;

/**
 * Foto del documento que se va a cobrar, en el vocabulario de Finanzas.
 *
 * Es el contrato que aísla a Finanzas de los módulos: aquí no hay `PmsReserva` ni
 * `TourReserva`, sólo "cuánto se debe, en qué moneda y a quién". Gracias a eso el módulo
 * de cobros no importa una sola clase del PMS y puede vivir en un proyecto donde el PMS
 * ni siquiera esté instalado.
 *
 * Lo devuelve el resolver de cada módulo (`FinOrigenCobroResolverInterface::resolver()`).
 */
final readonly class FinOrigenCobroDto
{
    /**
     * @param string      $descripcion      Texto que ve el cliente en la página de pago ("Estancia Casita 7, 12-15 ago").
     * @param string      $referencia       Identificador legible del documento (localizador). Va al `orderId` de la pasarela.
     * @param string      $saldoPendiente   Importe que falta cobrar, con 2 decimales, en `moneda`. '0.00' si está saldado.
     * @param string      $moneda           ISO 4217 alfa-3 ('PEN', 'USD'). Es el id de `MaestroMoneda`.
     * @param string|null $clienteNombre    Para prellenar el formulario de la pasarela. **Sólo el
     *                                      nombre**: el apellido va aparte desde el 31/08/2026,
     *                                      porque juntarlos aquí obligaba a adivinarlos después.
     * @param string|null $clienteApellido
     * @param string|null $clienteEmail     Izipay lo usa para mandar el comprobante al comprador.
     * @param string|null $clienteTelefono
     */
    public function __construct(
        public string $descripcion,
        public string $referencia,
        public string $saldoPendiente,
        public string $moneda,
        public ?string $clienteNombre = null,
        public ?string $clienteApellido = null,
        public ?string $clienteEmail = null,
        public ?string $clienteTelefono = null,
    ) {}
}
