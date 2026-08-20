<?php

declare(strict_types=1);

namespace App\Operacion\Service;

use App\Contract\ConversationMilestoneInterface;
use App\Message\Contract\HitoDeAsunto;
use App\Operacion\Entity\OperacionServicio;
use App\Operacion\Enum\EstadoOperacionEnum;
use DateTimeImmutable;

/**
 * Los momentos con nombre de un viaje, uno por servicio operativo.
 *
 * ── La misma forma que la estancia partida, y no es casualidad ──────────────
 * {@see \App\Pms\Service\Message\PmsHitosDeEstancia} descubrió que resumir una estancia en dos
 * fechas —`start` y `end`— pierde todo lo de en medio: el día que el huésped se va a Machu
 * Picchu, el día que vuelve, el día que cambia de casita. Un viaje tiene el mismo problema y
 * peor: **son todo días de en medio**.
 *
 * ```
 *   Cusco–Puno–Arequipa, 8 días
 *     día 1  traslado del aeropuerto
 *     día 2  city tour con guía
 *     día 4  bus a Puno
 *     día 6  Islas Uros
 *     día 8  traslado de salida
 *
 *   con start/end:  «tu viaje empieza el 1 y acaba el 8»
 * ```
 *
 * Y el pasajero no necesita saber cuándo empieza su viaje: necesita saber **a qué hora le
 * recogen mañana**. Cada `OperacionServicio` es un hito con su fecha y su hora, o —en las
 * palabras del dueño del producto— un *mini-milestone*.
 *
 * ── Qué NO sale de aquí ─────────────────────────────────────────────────────
 * ⚠️ Ni el coste al proveedor (`OperacionOrdenServicio::$totalOs`) ni el estado del papeleo
 * (`$estadoOs`). El detalle de cada hito es lo que se le puede leer al pasajero —qué servicio y
 * a qué hora—, y el recorte se hace **aquí, en la fuente**, no confiando en que un prompt lo
 * prohíba: eso ya falló esta semana con los teléfonos del personal de limpieza.
 *
 * Si algún día hace falta decirle «tu tour está confirmado», el estado que responde a eso es
 * `OperacionServicio::$estadoReservaProveedor` —«¿el proveedor ya me confirmó?»—, nunca el de la
 * orden de compra. Ver docs/Operacion.md §4.1.
 *
 * ── Derivación pura ─────────────────────────────────────────────────────────
 * Entra un expediente, salen sus hitos. Sin base de datos ni servicios, para poder probar la
 * combinatoria con entidades en memoria.
 */
final readonly class OperacionHitosDeViaje
{
    /**
     * Recibe los SERVICIOS y no el expediente a propósito.
     *
     * `OperacionServicio` apunta a su `CotizacionFile`, pero la relación no tiene lado inverso:
     * añadirlo sólo para esto obligaría a Doctrine a arrastrar la colección entera de un
     * expediente —que en un viaje largo son decenas de filas— cada vez que se quiera saber una
     * fecha. Quien llama ya sabe consultarlos acotados, y así esta clase se queda como lo que es:
     * derivación pura, probable con objetos en memoria.
     *
     * @param iterable<OperacionServicio> $servicios Los del expediente, en cualquier orden.
     * @return list<HitoDeAsunto> En orden cronológico. Vacía si no hay servicios vivos con fecha,
     *                            que es lo normal mientras el viaje sólo está cotizado.
     */
    public function para(iterable $servicios): array
    {
        $vivos = [];

        foreach ($servicios as $servicio) {
            if ($this->cuenta($servicio)) {
                $vivos[] = $servicio;
            }
        }

        if ($vivos === []) {
            return [];
        }

        $servicios = $vivos;

        usort($servicios, fn (OperacionServicio $a, OperacionServicio $b): int => $this->cuando($a) <=> $this->cuando($b));

        $hitos = [];
        $ultimo = count($servicios) - 1;

        foreach ($servicios as $i => $servicio) {
            // El primero y el último llevan además `START`/`END`, para que las reglas que hoy
            // cuelgan de esos dos hitos —la bienvenida, la despedida— sigan encontrando dónde
            // engancharse sin tener que reescribirlas. Los de en medio son el detalle nuevo.
            $tipo = match (true) {
                $i === 0 => ConversationMilestoneInterface::START,
                $i === $ultimo => ConversationMilestoneInterface::END,
                default => ConversationMilestoneInterface::SERVICE,
            };

            $hitos[] = new HitoDeAsunto($tipo, $this->cuando($servicio), $servicio->getDescripcionServicio());

            // Con un solo servicio, ese servicio es el principio y el fin del viaje: se emiten
            // los dos hitos sobre la misma fecha en vez de elegir uno, porque una regla de
            // bienvenida y una de despedida son mensajes distintos y las dos deben poder disparar.
            if ($i === 0 && $ultimo === 0) {
                $hitos[] = new HitoDeAsunto(
                    ConversationMilestoneInterface::END,
                    $this->cuando($servicio),
                    $servicio->getDescripcionServicio()
                );
            }
        }

        return $hitos;
    }

    /**
     * ¿Este servicio cuenta?
     *
     * Fuera lo cancelado —no hay nada que anunciar— y fuera lo que no tiene fecha, que no se
     * puede programar. Un servicio sin fecha no es un error del que haya que quejarse aquí: La
     * Biblia admite filas a medio rellenar mientras el operador las cuadra.
     */
    private function cuenta(OperacionServicio $servicio): bool
    {
        return $servicio->getFechaServicio() !== null
            && $servicio->getEstadoOperacion() !== EstadoOperacionEnum::CANCELADO;
    }

    /**
     * La fecha con su hora de recojo, cuando la hay.
     *
     * `horaRecojo` es texto libre («06:15») porque el operador la teclea como se la da el
     * proveedor. Si no se puede leer, se deja el día a secas en vez de inventarse una hora: un
     * mensaje que llega el día correcto a una hora rara es recuperable; uno que llega el día
     * equivocado, no.
     */
    private function cuando(OperacionServicio $servicio): DateTimeImmutable
    {
        $fecha = DateTimeImmutable::createFromInterface($servicio->getFechaServicio())->setTime(0, 0);
        $hora = trim((string) $servicio->getHoraRecojo());

        if (preg_match('/^(\d{1,2}):(\d{2})/', $hora, $partes) !== 1) {
            return $fecha;
        }

        return $fecha->setTime((int) $partes[1], (int) $partes[2]);
    }
}
