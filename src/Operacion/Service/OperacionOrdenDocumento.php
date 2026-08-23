<?php

declare(strict_types=1);

namespace App\Operacion\Service;

use App\Operacion\Entity\OperacionOrdenServicio;
use App\Operacion\Entity\OperacionOrdenServicioItem;

/**
 * El texto que se le manda al proveedor: **qué operar**, no cuánto cuesta.
 *
 * ── Por qué no lleva importes ───────────────────────────────────────────────
 * Es la regla que ya estaba escrita en `OperacionOrdenServicio::$totalOs`: «al proveedor no se
 * le manda un total». Aquí se extiende a las líneas, y por el mismo motivo: este documento es
 * una **solicitud de servicio** —a quién recoger, dónde, a qué hora y cuántos son— y el dinero
 * ya está pactado por otro canal. Meterlo abre una negociación en el peor momento, cuando lo
 * que hace falta es que la plaza exista.
 *
 * Lo que se paga se lleva aparte: {@see \App\Operacion\Entity\OperacionPago}.
 *
 * ── Sale de los ITEMS, no de los servicios ──────────────────────────────────
 * Los ítems son la copia **congelada** al emitir. Componer el documento desde los servicios
 * vivos haría que reenviarlo un mes después mandara algo distinto de lo que el proveedor tiene
 * en la mano, sin que nada lo dijera — y ése es justo el escenario en que se reenvía.
 *
 * ⚠️ Una orden en borrador no tiene ítems: el documento sale vacío, y por eso enviar sólo se
 * ofrece a partir de emitida.
 */
final readonly class OperacionOrdenDocumento
{
    /**
     * @return array{asunto: string, cuerpo: string, lineas: int}
     */
    public function para(OperacionOrdenServicio $orden): array
    {
        $lineas = [];

        // Qué ítems enseñan el recojo: uno al día, salvo que cambie — lo decide la orden, que es
        // quien ve todas sus líneas. Ver `OperacionOrdenServicio::getRutasVisibles()`.
        $rutas = $orden->getRutasVisibles();

        foreach ($orden->getItems() as $item) {
            $lineas[] = $this->linea($item, $rutas);
        }

        $cuerpo = sprintf(
            "Orden de Servicio %s\n\n%s\n\n%s",
            $orden->getNumeroOs(),
            $lineas === []
                ? '(sin líneas: la orden todavía no se ha emitido)'
                : implode("\n", $lineas),
            'Por favor confirmar recepción y disponibilidad.'
        );

        return [
            'asunto' => sprintf('Orden de Servicio %s', $orden->getNumeroOs()),
            'cuerpo' => $cuerpo,
            'lineas' => count($lineas),
        ];
    }

    /**
     * Una línea del documento.
     *
     * El orden es el de quien lo va a operar: primero CUÁNDO, luego QUÉ, y al final los datos
     * que necesita para presentarse —hora de recojo y cuántos son—. La descripción va después
     * de la fecha a propósito: el proveedor busca por día, no por nombre de servicio.
     */
    /** @param array<string, string> $rutas id de ítem → línea de recojo, si le toca enseñarla */
    private function linea(OperacionOrdenServicioItem $item, array $rutas): string
    {
        $partes = [];

        if (($fecha = $item->getFechaServicio()) !== null) {
            $partes[] = $fecha->format('d/m/Y');
        }

        $hora = trim((string) $item->getHora());

        if ($hora !== '') {
            $partes[] = $hora;
        }

        $partes[] = $item->getDescripcion();

        // La hora de recojo CONFIRMADA es la que vale; si no la hay todavía, no se inventa.
        //
        // ⚠️ Y sólo se dice cuando DIFIERE de la hora del servicio. En los datos reales coinciden
        // casi siempre —«04:00 · Tacama · recojo 04:00»— y repetir el mismo dato dos veces por
        // línea enseña a no leerlo, que es exactamente lo contrario de lo que hace falta el día
        // que sí sean distintas.
        if (($recojo = trim((string) $item->getHoraRecojoConfirmada())) !== '' && $recojo !== $hora) {
            $partes[] = sprintf('recojo %s', $recojo);
        }

        if (($pax = $item->getCantidadPax()) !== null && $pax > 0) {
            $partes[] = sprintf('%d pax', $pax);
        }

        // ── Dónde recoge y dónde deja ───────────────────────────────────────
        //
        // Va en su propio renglón: metida en la ristra de la línea, entre la hora y los pax, una
        // dirección de cuarenta caracteres sepulta todo lo demás. La redacción la compone el
        // ítem, que es también quien la pinta en la página pública — ver `rutaParaLaOrden()`.
        $ruta = $rutas[$item->getId()?->toRfc4122() ?? ''] ?? null;

        // El prestador va sólo cuando NO es el destinatario: si coinciden, decírselo es ruido.
        $prestador = trim((string) $item->getPrestadorNombre());
        $comprador = trim((string) $item->getOrden()?->getCompradorNombre());

        if ($prestador !== '' && $prestador !== $comprador) {
            $partes[] = sprintf('opera %s', $prestador);
        }

        $linea = '· ' . implode('  ·  ', $partes);

        return $ruta === null ? $linea : $linea . "\n    " . $ruta;
    }
}
