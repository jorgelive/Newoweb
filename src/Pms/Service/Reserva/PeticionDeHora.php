<?php

declare(strict_types=1);

namespace App\Pms\Service\Reserva;

use App\Pms\Entity\PmsEventoCalendario;
use App\Pms\Entity\PmsPeticion;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;

/**
 * La hora que pide un huésped y que todavía no está apuntada: una petición pegada a su estancia.
 *
 * La deja `ConfirmarHoraSkill` cuando la hora no se puede apuntar sola (fuera del horario, o de
 * madrugada del día siguiente) y la cierra `AplicarCambioHorarioSkill` cuando el equipo decide.
 * Es una `PmsPeticion` corriente para que la vean donde ya se miran las peticiones: el drawer de
 * la reserva y la lista de llegadas y salidas.
 *
 * Una sola por extremo y estancia: si el huésped vuelve a pedir otra hora, se reescribe la misma
 * en vez de amontonar «pide entrar a las 8», «pide entrar a las 9»… Se reconoce por el principio
 * del texto, que es lo único que tiene una petición para distinguirse de otra.
 */
final readonly class PeticionDeHora
{
    private const array PREFIJOS_LLEGADA = ['Pide entrar', 'Llega de madrugada'];
    private const array PREFIJOS_SALIDA = ['Pide salir'];

    public function __construct(
        private EntityManagerInterface $em,
    ) {}

    /** El texto tiene que empezar por uno de los prefijos del extremo. NO hace flush. */
    public function dejar(PmsEventoCalendario $evento, string $texto, bool $esSalida, ?string $conversacionId): void
    {
        $existente = $this->pendientes($evento, $esSalida)[0] ?? null;

        if ($existente !== null) {
            $existente->setTexto($texto);

            return;
        }

        $peticion = (new PmsPeticion())
            ->setEvento($evento)
            ->setTexto($texto)
            ->setConversacionId($conversacionId);
        $peticion->initializeId();

        $this->em->persist($peticion);
    }

    /** El equipo ya decidió la hora de ese extremo: lo pedido deja de estar pendiente. NO hace flush. */
    public function cerrar(PmsEventoCalendario $evento, bool $esSalida): void
    {
        foreach ($this->pendientes($evento, $esSalida) as $peticion) {
            $peticion->setEfectuadaAt(new DateTimeImmutable());
        }
    }

    /** @return list<PmsPeticion> */
    private function pendientes(PmsEventoCalendario $evento, bool $esSalida): array
    {
        if ($evento->getId() === null) {
            return [];
        }

        $prefijos = $esSalida ? self::PREFIJOS_SALIDA : self::PREFIJOS_LLEGADA;

        return array_values(array_filter(
            $this->em->getRepository(PmsPeticion::class)->findBy(['evento' => $evento]),
            static function (PmsPeticion $p) use ($prefijos): bool {
                if (!$p->isPendiente()) {
                    return false;
                }
                foreach ($prefijos as $prefijo) {
                    if (str_starts_with($p->getTexto(), $prefijo)) {
                        return true;
                    }
                }

                return false;
            }
        ));
    }
}
