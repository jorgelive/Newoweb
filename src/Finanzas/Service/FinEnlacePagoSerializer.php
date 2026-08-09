<?php

declare(strict_types=1);

namespace App\Finanzas\Service;

use App\Finanzas\Entity\FinEnlacePago;

/**
 * Forma JSON de un enlace de pago para las SPA internas.
 *
 * Servicio y no un método privado del controlador porque lo consumen DOS pantallas —el
 * panel de una reserva y la vista global de cobros— y con la copia duplicada ya pasó lo
 * de siempre: se añade un campo en una y la otra se queda corta sin que nada falle.
 *
 * A mano y no con el serializer de Symfony porque `url` no es un campo de la entidad (se
 * compone con el host de `pax`) y es justo el dato que va a copiar el operador.
 *
 * Espejo en TS: `util/src/types/finEnlacePagoModel.ts`.
 */
final class FinEnlacePagoSerializer
{
    public function __construct(
        private readonly FinEnlacePagoService $servicio,
    ) {}

    /** @return array<string, mixed> */
    public function aArray(FinEnlacePago $enlace): array
    {
        return [
            'id' => (string) $enlace->getId(),
            'url' => $this->servicio->urlPublica($enlace),
            // Con qué pasarela se emitió. Se pinta en el listado porque con dos conectores
            // en paralelo, "por dónde entró este cobro" es la primera pregunta al conciliar.
            'pasarela' => $enlace->getPasarela()->value,
            'pasarelaEtiqueta' => $enlace->getPasarela()->etiqueta(),
            'estado' => $enlace->getEstado()->value,
            'estadoEtiqueta' => $enlace->getEstado()->etiqueta(),
            'vigente' => $enlace->estaVigente(),
            'moneda' => $enlace->getMonedaCodigo(),
            'monedaSimbolo' => $enlace->getMonedaSimbolo(),
            'montoNeto' => $enlace->getMontoNeto(),
            'montoRecargo' => $enlace->getMontoRecargo(),
            'montoTotal' => $enlace->getMontoTotal(),
            'recargoPorcentaje' => $enlace->getRecargoPorcentaje(),
            'concepto' => $enlace->getConcepto(),
            'ordenId' => $enlace->getOrdenId(),
            'expiraEn' => $enlace->getExpiraEn()?->format(DATE_ATOM),
            'pagadoEn' => $enlace->getPagadoEn()?->format(DATE_ATOM),
            'medioDetalle' => $enlace->getMedioDetalle(),
            'autorizacionCodigo' => $enlace->getAutorizacionCodigo(),
            'creadoPorNombre' => $enlace->getCreadoPorNombre(),
            'createdAt' => $enlace->getCreatedAt()?->format(DATE_ATOM),
            // Sólo en la vista global: allí la fila no está dentro de una reserva, así que
            // sin esto no se sabe de quién es el cobro ni se puede enlazar a su ficha.
            'origenTipo' => $enlace->getOrigenTipo()->value,
            'origenId' => (string) $enlace->getOrigenId(),
            'origenReferencia' => $enlace->getOrigenReferencia(),
            'clienteNombre' => $enlace->getClienteNombre(),
        ];
    }
}
