<?php

declare(strict_types=1);

namespace App\Cotizacion\Service\Padron;

use App\Cotizacion\Entity\CotizacionFile;
use App\Cotizacion\Entity\CotizacionFilepasajero;
use App\Cotizacion\Enum\AlcanceDeVistaEnum;
use App\Security\Roles;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Quién ve a quién en el padrón de un expediente.
 *
 * ## La regla, entera
 *
 * ```
 * sesión con ROLE_RESERVAS_SHOW  →  TODO, invitados incluidos      (es la agencia)
 * Supervisor                     →  el expediente MENOS invitados
 * Coordinador                    →  sus grupos, MENOS invitados
 * Alumno · Padre · Invitado      →  sólo su ficha
 * sin tipo                       →  sólo su ficha
 * ```
 *
 * ## ⚠️ Los invitados no se filtran: no existen
 *
 * Son gratuidades de la agencia, acordadas fuera del contrato con el colegio. Se caen de **toda**
 * vista pública, no sólo de la del supervisor.
 *
 * Filtrarlos por rol dejaría huecos —una habitación con una cama de menos, una cuenta que no
 * cuadra— y un hueco se pregunta igual que un nombre. Que desaparezcan para todos es lo único que
 * no invita a preguntar.
 *
 * ⚠️ **Salvo para la agencia**, que es quien los paga y tiene que verlos.
 *
 * ## Por qué el rol y no «estar logueado»
 *
 * Hoy sólo el personal de OpenPeru tiene cuenta, así que las dos condiciones dan lo mismo. Se
 * escribe con el rol porque es lo que la regla dice de verdad —*es la agencia*— y no cuesta nada:
 * el día que alguien de fuera tenga cuenta para otra cosa, no hereda las gratuidades.
 */
final readonly class AlcanceDelPadron
{
    public function __construct(private Security $security) {}

    /** ¿Quien pregunta es la agencia? */
    public function esAgencia(): bool
    {
        return $this->security->isGranted(Roles::RESERVAS_SHOW);
    }

    /**
     * El alcance de quien pregunta.
     *
     * `null` como pasajero significa «no se identificó»: si además no es agencia, no ve nada, y
     * eso lo decide {@see self::pasajerosVisibles()} devolviendo una lista vacía.
     */
    public function alcanceDe(?CotizacionFilepasajero $quienPregunta): AlcanceDeVistaEnum
    {
        if ($this->esAgencia()) {
            return AlcanceDeVistaEnum::AGENCIA;
        }

        return $quienPregunta?->getAlcanceDeVista() ?? AlcanceDeVistaEnum::SOLO_YO;
    }

    /**
     * A qué pasajeros del expediente puede ver.
     *
     * @return list<CotizacionFilepasajero>
     */
    public function pasajerosVisibles(CotizacionFile $file, ?CotizacionFilepasajero $quienPregunta): array
    {
        $alcance = $this->alcanceDe($quienPregunta);

        if ($alcance === AlcanceDeVistaEnum::AGENCIA) {
            return array_values($file->getFilepasajeros()->toArray());
        }

        if ($quienPregunta === null) {
            return [];
        }

        // ⚠️ El propio invitado SIEMPRE se ve a sí mismo. `esExpuesto()` dice si lo ven los DEMÁS;
        // aplicarlo también a uno mismo dejaría a las siete gratuidades sin poder consultar su
        // propio viaje, que es justo lo contrario de regalárselo.
        $candidatos = match ($alcance) {
            AlcanceDeVistaEnum::EXPEDIENTE => array_values($file->getFilepasajeros()->toArray()),
            AlcanceDeVistaEnum::SUS_GRUPOS => $this->companerosDeGrupo($quienPregunta),
            // Sin brazo para AGENCIA: ya salió arriba, y PHPStan lo confirma. Ponerlo «por si
            // acaso» sería código que no se ejecuta nunca fingiendo cubrir un caso.
            AlcanceDeVistaEnum::SOLO_YO => [$quienPregunta],
        };

        $visibles = array_values(array_filter(
            $candidatos,
            static fn (CotizacionFilepasajero $p): bool => $p->isExpuesto(),
        ));

        return $this->conElPropio($visibles, $quienPregunta);
    }

    /**
     * Los de sus grupos, él incluido.
     *
     * @return list<CotizacionFilepasajero>
     */
    private function companerosDeGrupo(CotizacionFilepasajero $quienPregunta): array
    {
        $vistos = [];

        foreach ($quienPregunta->grupos() as $grupo) {
            foreach ($grupo->getMiembros() as $pertenencia) {
                $companero = $pertenencia->getPasajero();
                if ($companero !== null) {
                    $vistos[(string) $companero->getId()] = $companero;
                }
            }
        }

        return array_values($vistos);
    }

    /**
     * @param list<CotizacionFilepasajero> $visibles
     *
     * @return list<CotizacionFilepasajero>
     */
    private function conElPropio(array $visibles, CotizacionFilepasajero $quienPregunta): array
    {
        foreach ($visibles as $p) {
            if ($p === $quienPregunta) {
                return $visibles;
            }
        }

        return [$quienPregunta, ...$visibles];
    }
}
