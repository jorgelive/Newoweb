<?php

declare(strict_types=1);

namespace App\Pms\Service\Message;

use App\Message\Contract\CandidatosDeAsuntoInterface;
use App\Message\Dto\CandidatoDeAsunto;
use App\Message\Entity\Message;
use App\Message\Entity\MessageConversation;
use App\Message\Service\Conversacion\EnlacesDeConversacion;
use App\Pms\Entity\PmsConversacionEnlace;
use App\Pms\Entity\PmsEventoCalendario;
use App\Pms\Entity\PmsEventoEstado;
use App\Pms\Service\Reserva\BuscadorDeEstancias;
use DateTimeImmutable;
use Symfony\Component\String\UnicodeString;

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
        $chat = $busqueda === '' ? self::normalizar($this->loQueHaEscrito($hilo)) : '';
        $puntuados = [];

        foreach ($porReserva as $id => $tramos) {
            $reserva = $tramos[0]->getReserva();
            $motivo = match (true) {
                $chat !== '' && self::laNombra($chat, $reserva?->getNombreCliente(), $reserva?->getApellidoCliente()) => 'la nombra en el chat',
                $busqueda === '' && $this->prefijoComun($telefono, (string) $reserva?->getTelefono()) >= 4 => 'mismo prefijo',
                default => null,
            };

            $puntuados[] = [
                new CandidatoDeAsunto(PmsConversacionEnlace::CONTEXT_TYPE, $id, $this->etiqueta($tramos), $motivo),
                match ($motivo) { 'la nombra en el chat' => 2, 'mismo prefijo' => 1, default => 0 },
            ];
        }

        // Lo que la persona dijo pesa más que el prefijo; a igualdad, el orden del buscador.
        usort($puntuados, static fn (array $a, array $b): int => $b[1] <=> $a[1]);

        return array_slice(array_map(static fn (array $p): CandidatoDeAsunto => $p[0], $puntuados), 0, self::LIMITE);
    }

    /**
     * ¿Aparece en lo que escribió el nombre o el apellido de quien reservó?
     *
     * Es la respuesta a la pregunta del agente —«¿a nombre de quién está la reserva?»—: si
     * contesta «Bruna», la reserva de Bruna Coelho sube arriba. Palabra entera y sin acentos, y
     * sólo palabras de 3 letras o más: «Ana» casa, una «de» del apellido no. No enlaza nada: un
     * nombre no prueba quién es, lo decide una persona con un toque.
     */
    public static function laNombra(string $chatNormalizado, ?string $nombre, ?string $apellido): bool
    {
        foreach (preg_split('/\s+/', self::normalizar(trim($nombre . ' ' . $apellido)), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $palabra) {
            if (mb_strlen($palabra) >= 3 && preg_match('/\b' . preg_quote($palabra, '/') . '\b/', $chatNormalizado) === 1) {
                return true;
            }
        }

        return false;
    }

    /** Lo último que escribió quien está en este hilo: es ahí donde contesta de quién es la reserva. */
    private function loQueHaEscrito(MessageConversation $hilo): string
    {
        $textos = [];

        foreach ($hilo->getMessages() as $mensaje) {
            if ($mensaje->getDirection() === Message::DIRECTION_INCOMING) {
                $textos[] = $mensaje->getTextoEntrante() . ' ' . $mensaje->getContentLocal();
            }
        }

        return implode(' ', array_slice($textos, -30));
    }

    public static function normalizar(string $texto): string
    {
        return trim((string) preg_replace('/\s+/', ' ', (new UnicodeString($texto))->ascii()->lower()->toString()));
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
