<?php

declare(strict_types=1);

namespace App\Pms\Service\Message;

use App\Message\Contract\CandidatosDeAsuntoInterface;
use App\Message\Dto\CandidatoDeAsunto;
use App\Message\Entity\MessageConversation;
use App\Message\Service\Conversacion\EnlacesDeConversacion;
use App\Pms\Entity\PmsConversacionEnlace;
use App\Pms\Entity\PmsEventoCalendario;
use App\Pms\Entity\PmsEventoEstado;
use App\Pms\Service\Reserva\BuscadorDeEstancias;
use DateTimeImmutable;

/**
 * Las reservas que podrían ser las de un hilo.
 *
 * Carla escribió «les escribo sobre mi reserva» desde un +55 85 sin decir de quién (12/09/2026), y
 * Jorge dio con ella porque la única huésped brasileña alojada, Bruna, era también +55 85. Es lo
 * que se ordena aquí: alojadas o llegando en ±2 días, y arriba las del mismo país y zona. Con
 * búsqueda, lo que case por nombre, localizador o casita.
 *
 * Una fila por RESERVA, no por estancia: lo que se enlaza es la reserva, y una de dos casitas
 * salía dos veces.
 */
final readonly class PmsCandidatosDeAsunto implements CandidatosDeAsuntoInterface
{
    private const int LIMITE = 8;

    public function __construct(
        private BuscadorDeEstancias $buscador,
        private EnlacesDeConversacion $enlaces,
    ) {}

    public function candidatos(MessageConversation $hilo, ?string $busqueda): array
    {
        $busqueda = trim((string) $busqueda);
        $estancias = mb_strlen($busqueda) >= 2
            ? $this->buscador->porTexto($busqueda, 25)
            : $this->buscador->deEstosDias(new DateTimeImmutable('today'));

        $yaEnlazadas = [];
        foreach ($this->enlaces->de($hilo) as $enlace) {
            $yaEnlazadas[$enlace->getContextId()] = true;
        }

        /** @var array<string, non-empty-list<PmsEventoCalendario>> $porReserva */
        $porReserva = [];
        foreach ($estancias as $estancia) {
            $id = $estancia->getReserva()?->getId()?->toRfc4122();
            $estado = $estancia->getEstado()?->getId();

            if ($id === null || isset($yaEnlazadas[$id])
                || in_array($estado, [PmsEventoEstado::CODIGO_CANCELADA, PmsEventoEstado::CODIGO_BLOQUEO], true)) {
                continue;
            }

            $porReserva[$id][] = $estancia;
        }

        $telefono = preg_replace('/\D/', '', (string) $hilo->getGuestPhone()) ?? '';
        $candidatos = [];

        foreach ($porReserva as $id => $tramos) {
            $reserva = $tramos[0]->getReserva();
            $mismoPrefijo = $busqueda === '' && $this->prefijoComun($telefono, (string) $reserva?->getTelefono()) >= 4;

            $candidatos[] = new CandidatoDeAsunto(
                PmsConversacionEnlace::CONTEXT_TYPE,
                $id,
                $this->etiqueta($tramos),
                $mismoPrefijo ? 'mismo prefijo' : null,
            );
        }

        // Los que tienen motivo, primero; el resto conserva el orden del buscador.
        usort($candidatos, static fn (CandidatoDeAsunto $a, CandidatoDeAsunto $b): int => ($b->motivo !== null) <=> ($a->motivo !== null));

        return array_slice($candidatos, 0, self::LIMITE);
    }

    /** @param non-empty-list<PmsEventoCalendario> $tramos */
    private function etiqueta(array $tramos): string
    {
        $reserva = $tramos[0]->getReserva();
        $casitas = array_values(array_unique(array_filter(array_map(
            static fn (PmsEventoCalendario $e): string => (string) $e->getPmsUnidad()?->getNombre(),
            $tramos,
        ))));
        $inicios = array_filter(array_map(static fn (PmsEventoCalendario $e) => $e->getInicio(), $tramos));
        $fines = array_filter(array_map(static fn (PmsEventoCalendario $e) => $e->getFin(), $tramos));

        return implode(' · ', array_filter([
            $reserva?->getNombreApellido() ?: 'Sin nombre',
            $reserva?->getLocalizador(),
            implode(' + ', $casitas),
            $inicios !== [] && $fines !== [] ? min($inicios)->format('d/m') . '→' . max($fines)->format('d/m') : null,
        ]));
    }

    /**
     * Dígitos iniciales en común entre dos teléfonos, hasta 4: el código de país y el de zona
     * (+55 85 Fortaleza, +51 98 móvil peruano). Más allá ya es casualidad.
     */
    private function prefijoComun(string $a, string $b): int
    {
        $b = preg_replace('/\D/', '', $b) ?? '';
        $n = 0;

        while ($n < 4 && isset($a[$n], $b[$n]) && $a[$n] === $b[$n]) {
            ++$n;
        }

        return $n;
    }
}
