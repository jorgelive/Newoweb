<?php

declare(strict_types=1);

namespace App\Contract\Nombre;

use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Throwable;

/**
 * Reparte una corrección de nombre entre quienes guardan copia, sin saber quiénes son.
 *
 * El emisor —hoy el PMS— llama aquí y se acabó. Quién recoge el aviso lo deciden las clases que
 * implementen {@see CopiaDelNombre}, que se enchufan solas por la etiqueta.
 */
final readonly class PropagadorDeNombre
{
    /** @param iterable<CopiaDelNombre> $copias */
    public function __construct(
        #[AutowireIterator('app.copia_del_nombre')]
        private iterable $copias,
        private LoggerInterface $logger,
        private EntityManagerInterface $em,
    ) {}

    /**
     * @return array<string, int> Qué se tocó y cuánto, para dejarlo dicho en el log.
     */
    public function propagar(CorreccionDeNombre $correccion): array
    {
        if (!$correccion->valeLaPena()) {
            return [];
        }

        $hecho = [];

        foreach ($this->copias as $copia) {
            try {
                $tocadas = $copia->corregir($correccion);
            } catch (Throwable $e) {
                // ⚠️ **Una copia que revienta no puede tumbar a las demás ni al guardado que la
                // disparó.** Esto corre pegado a un flush de la reserva: propagar la excepción
                // convertiría «no se pudo actualizar un título de calendario» en «no se pudo
                // guardar la reserva». Mismo criterio que el aviso de cobro.
                $this->logger->error(sprintf(
                    '[Nombre] «%s» falló al corregir %s %s: %s',
                    $copia->queCopia(),
                    $correccion->origenTipo,
                    $correccion->origenId,
                    $e->getMessage(),
                ));

                // ⚠️ **Si el EM se cerró, no se sigue.** Un fallo SQL dentro de una copia hace que
                // Doctrine cierre el `EntityManager`: a partir de ahí cada copia siguiente lanza
                // `EntityManagerClosed`, que llena el log de errores que no señalan al culpable —
                // y peor, el primero que lo toque FUERA de este bucle hace que estalle el
                // `postFlush` entero y el guardado parezca haber fallado con la reserva ya
                // escrita. Se para aquí y se dice qué copia lo rompió.
                if (!$this->em->isOpen()) {
                    $this->logger->error(sprintf(
                        '[Nombre] «%s» cerró el EntityManager: no se propaga a las demás copias.',
                        $copia->queCopia(),
                    ));

                    break;
                }

                continue;
            }

            if ($tocadas > 0) {
                $hecho[$copia->queCopia()] = $tocadas;
            }
        }

        if ($hecho !== []) {
            $this->logger->notice(sprintf(
                '[Nombre] %s %s: «%s» → «%s». Copias al día: %s.',
                $correccion->origenTipo,
                $correccion->origenId,
                $correccion->completoAntes(),
                $correccion->completoAhora(),
                implode(', ', array_map(
                    static fn (string $que, int $n): string => sprintf('%s (%d)', $que, $n),
                    array_keys($hecho),
                    $hecho,
                )),
            ));
        }

        return $hecho;
    }

    /**
     * Cuántas copias están desincronizadas hoy, por sitio. Sólo lee.
     *
     * @return array<string, int>
     */
    public function auditar(): array
    {
        $informe = [];

        foreach ($this->copias as $copia) {
            $informe[$copia->queCopia()] = $copia->desincronizadas();
        }

        return $informe;
    }
}
