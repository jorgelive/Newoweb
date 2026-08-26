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
        // Qué ítems enseñan el recojo: uno al día, salvo que cambie — lo decide la orden, que es
        // quien ve todas sus líneas. Ver `OperacionOrdenServicio::getRutasVisibles()`.
        $rutas = $orden->getRutasVisibles();

        // ⚠️ ORDENADOS, y por la misma vía que `getRutasVisibles()`. Iterando la colección cruda
        // se imprimía en el orden en que se marcaron las filas: las fechas salían a saltos y —peor—
        // la regla del recojo, que sí mira la lista ordenada, dejaba sin «Recoge en…» a líneas que
        // debían llevarlo. Dos listas distintas para el mismo documento no pueden coincidir.
        //
        // ── AGRUPADO POR DÍA ────────────────────────────────────────────────
        // Antes cada línea repetía su fecha y todas iban seguidas: un bloque de cinco renglones
        // idénticos en el que hay que leerlo entero para saber cuántos días son. El proveedor
        // organiza por jornada —«el miércoles tengo tres»—, así que el día encabeza y las suyas
        // van debajo. Es la misma información con la mitad de esfuerzo.
        $porDia = [];
        $bloques = 0;

        foreach ($orden->getItemsOrdenados() as $item) {
            $clave = $item->getFechaServicio()?->format('Y-m-d') ?? '';
            $porDia[$clave]['etiqueta'] ??= $item->getEtiquetaDia();
            $porDia[$clave]['lineas'][] = $this->linea($item, $rutas);
            ++$bloques;
        }

        $partesCuerpo = [];

        foreach ($porDia as $dia) {
            // Los asteriscos son la negrita de WhatsApp. En un correo de texto plano se ven como
            // asteriscos —feo pero legible—; el formato de verdad va en la página y el PDF.
            $partesCuerpo[] = sprintf("*%s*\n%s", $dia['etiqueta'], implode("\n", $dia['lineas']));
        }

        $cuerpo = sprintf(
            "*Orden de Servicio %s*\n\n%s\n\n%s",
            $orden->getNumeroOs(),
            $partesCuerpo === []
                ? '(sin líneas: la orden todavía no se ha emitido)'
                : implode("\n\n", $partesCuerpo),
            'Por favor confirmar recepción y disponibilidad.'
        );


        return [
            'asunto' => sprintf('Orden de Servicio %s', $orden->getNumeroOs()),
            'cuerpo' => $cuerpo,
            'lineas' => $bloques,
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
        // ⚠️ La fecha YA NO va en la línea: la lleva el encabezado del día. Repetirla en cada
        // renglón era la mitad del ancho gastado en un dato que no cambia dentro del bloque.
        $partes = [];

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

        // El reloj marca dónde empieza cada servicio, que es lo que se busca al repasar el día.
        // Un icono y no un guion porque en una lista de cinco el ojo salta a la forma, no al signo.
        $linea = '🕐 ' . implode('  ·  ', $partes);

        // El pin va en su propio renglón, alineado bajo el reloj: es una dirección larga y metida
        // en la ristra sepulta la hora y los pax. Ver el comentario de arriba.
        return $ruta === null ? $linea : $linea . "\n📍 " . $ruta;
    }
}
