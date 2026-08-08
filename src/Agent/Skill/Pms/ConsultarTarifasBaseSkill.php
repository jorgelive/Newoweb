<?php

declare(strict_types=1);

namespace App\Agent\Skill\Pms;

use App\Agent\Access\ActorInterface;
use App\Agent\Access\NivelRiesgo;
use App\Agent\Skill\SkillDefinition;
use App\Agent\Skill\SkillInterface;
use App\Agent\Skill\SkillParameter;
use App\Agent\Skill\SkillResult;
use App\Pms\Entity\PmsUnidad;
use App\Security\Roles;
use Doctrine\ORM\EntityManagerInterface;

/**
 * La tarifa base de cada casita: el suelo al que se vende cuando nadie cargó tarifa.
 *
 * Skill aparte y no un campo más de `consultar_disponibilidad`, a propósito. Allí viajaba junto
 * al precio real y el modelo los recitaba seguidos —«Total: 135.00 USD · Tarifa base ref:
 * 70.00 USD»—: dos números para lo mismo, y el que NO se cobra puesto al lado del que sí.
 * Quien cotiza no necesita la base; sólo estorba.
 *
 * ### Para qué sirve de verdad
 *
 * Para el día que alguien olvida cargar las tarifas de un mes. Entonces esas noches se venden
 * al suelo sin que nadie lo decida, y esta skill es la que contesta «¿y cuánto es el suelo?».
 * Se usa poco y está bien que así sea: si hace falta a menudo, el problema es que faltan
 * tarifas, no que falte consultarlas.
 *
 * ⚠️ **Una tarifa base desactivada no significa gratis.** Significa que esa casita NO se puede
 * vender en las fechas sin rango: el motor no encuentra precio y no hay nada que cobrar. Se
 * dice explícitamente, porque «sin tarifa base» leído deprisa parece un cero.
 */
final readonly class ConsultarTarifasBaseSkill implements SkillInterface
{
    public function __construct(
        private EntityManagerInterface $em,
    ) {}

    public function nombre(): string
    {
        return 'consultar_tarifas_base';
    }

    public function definicion(): SkillDefinition
    {
        return new SkillDefinition(
            descripcion: 'Las tarifas BASE de las casitas: el precio de suelo que se aplica a '
                . 'una noche cuando no hay ninguna tarifa cargada para esa fecha. Úsala sólo si '
                . 'preguntan explícitamente por «la tarifa base», «el precio por defecto» o «a '
                . 'cuánto se vende si no hay tarifa puesta», y también cuando otra skill avise '
                . 'de noches sin tarifa y quieran saber a cuánto están saliendo. NO la uses '
                . 'para cotizar ni para responder «¿cuánto cuesta?»: para eso están '
                . 'consultar_disponibilidad y consultar_tarifas, que dan el precio real. Si una '
                . 'casita sale con la base DESACTIVADA, no es que sea gratis: es que no se '
                . 'puede vender en fechas sin tarifa cargada, y eso hay que decirlo tal cual.',
            parametros: [
                SkillParameter::texto('casita', 'Nombre o slug de una casita concreta. Omítelo '
                    . 'para ver todas.', requerido: false),
            ],
        );
    }

    public function rolesRequeridos(): array
    {
        return [Roles::RESERVAS_SHOW];
    }

    public function nivelRiesgo(): NivelRiesgo
    {
        return NivelRiesgo::Lectura;
    }

    public function ejecutar(array $entrada, ActorInterface $actor): SkillResult
    {
        $pedida = mb_strtolower(trim((string) ($entrada['casita'] ?? '')));

        $unidades = $this->em->getRepository(PmsUnidad::class)->findBy([], ['nombre' => 'ASC']);

        if ($pedida !== '') {
            $unidades = array_values(array_filter(
                $unidades,
                static fn (PmsUnidad $u): bool => str_contains(mb_strtolower((string) $u->getNombre()), $pedida)
                    || str_contains(mb_strtolower((string) $u->getSlug()), $pedida)
            ));
        }

        if ($unidades === []) {
            return SkillResult::error(
                'No encuentro esa casita. Dime su nombre tal como aparece en el panel, o no me '
                . 'digas ninguna y te doy todas.'
            );
        }

        $filas = [];
        $desactivadas = 0;

        foreach ($unidades as $unidad) {
            $activa = $unidad->isTarifaBaseActiva();

            if (!$activa) {
                ++$desactivadas;
            }

            $filas[] = array_filter([
                'casita' => $unidad->getNombre(),
                'tarifa_base' => $activa
                    ? sprintf(
                        '%.2f %s',
                        (float) $unidad->getTarifaBasePrecio(),
                        $unidad->getTarifaBaseMoneda()?->getId() ?? ''
                    )
                    : null,
                'estancia_minima' => $unidad->getTarifaBaseMinStay(),
                // No es un booleano suelto: leído por un modelo, `activa: false` invita a
                // decir «sin tarifa base» y que suene a gratis.
                'sin_base' => $activa
                    ? null
                    : 'Tarifa base DESACTIVADA: esta casita no se puede vender en fechas sin '
                        . 'tarifa cargada. No es que sea gratis, es que no hay precio.',
            ], static fn ($v) => $v !== null);
        }

        return SkillResult::ok(array_filter([
            'total' => count($filas),
            'casitas' => $filas,
            'para_que_sirve' => 'Es el precio de suelo, el que se aplica a una noche SIN tarifa '
                . 'cargada. No es lo que se cobra normalmente: para cotizar usa '
                . 'consultar_disponibilidad o consultar_tarifas.',
            'aviso' => $desactivadas > 0
                ? sprintf(
                    '%d casita(s) tienen la tarifa base desactivada y no se pueden vender en '
                    . 'fechas sin tarifa cargada.',
                    $desactivadas
                )
                : null,
        ], static fn ($v) => $v !== null));
    }
}
