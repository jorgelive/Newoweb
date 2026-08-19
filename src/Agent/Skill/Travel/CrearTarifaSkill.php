<?php

declare(strict_types=1);

namespace App\Agent\Skill\Travel;

use App\Agent\Access\ActorInterface;
use App\Agent\Access\NivelRiesgo;
use App\Agent\Skill\SkillDefinition;
use App\Agent\Skill\SkillDominioInterface;
use App\Agent\Skill\SkillInterface;
use App\Agent\Skill\SkillParameter;
use App\Agent\Skill\SkillResult;
use App\Security\Roles;
use App\Travel\Entity\TravelTarifa;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Añade una tarifa nueva a un componente del catálogo.
 *
 * Separada de {@see ModificarTarifaSkill} porque son dos intenciones distintas y el operador
 * las dice distinto. Las reglas de qué es una tarifa válida viven en {@see TarifaDesdeEntrada},
 * compartidas: escritas dos veces, dentro de tres meses una aceptaría lo que la otra rechaza.
 *
 * ⚠️ **Si el modelo duda entre crear y modificar, no adivina: pregunta.** Las dos son
 * `NivelRiesgo::Escritura`, así que empatan y salta la aclaración
 * ({@see \App\Agent\Conversation\AclaracionDeEmpate}). Eso es exactamente para lo que existe
 * ese mecanismo — y hasta el 19/08/2026 no podía dispararse.
 */
final readonly class CrearTarifaSkill implements SkillInterface, SkillDominioInterface
{
    public function __construct(
        private EntityManagerInterface $em,
        private TarifaDesdeEntrada $tarifas,
    ) {}

    public function nombre(): string
    {
        return 'crear_tarifa';
    }

    public function definicion(): SkillDefinition
    {
        return new SkillDefinition(
            descripcion: 'AÑADE una tarifa NUEVA a un componente del catálogo de servicios y '
                . 'tours —no a las casitas—. Úsala para «crea una tarifa de extranjero para el '
                . 'boleto», «añádele el precio de niños al city tour», «ponle una tarifa de '
                . 'grupo». Para cambiar una que ya existe es modificar_tarifa. CREA UN REGISTRO: '
                . 'llama SIEMPRE primero con confirmado=false y enséñale al operador la tarifa '
                . 'entera —precio, moneda y todas las condiciones— antes de pedirle el sí. Antes '
                . 'de crear, mira con buscar_tarifas si ya hay una igual: dos tarifas que sólo se '
                . 'distinguen por el precio son un error de carga, no una opción. Lo que no le '
                . 'pongas se queda sin límite, y eso significa que vale para cualquiera en ese '
                . 'eje: si el precio es sólo para extranjeros o sólo para niños, DILO.',
            parametros: array_merge(
                [
                    SkillParameter::texto('componente', 'Nombre del componente al que se le '
                        . 'añade la tarifa. Basta un trozo del nombre.'),
                ],
                TarifaDesdeEntrada::parametrosComunes()
            ),
        );
    }

    public function dominios(): array
    {
        return [];
    }

    public function rolesRequeridos(): array
    {
        return [Roles::OPERACIONES_WRITE];
    }

    public function nivelRiesgo(): NivelRiesgo
    {
        return NivelRiesgo::Escritura;
    }

    /** @param array<string, mixed> $entrada */
    public function ejecutar(array $entrada, ActorInterface $actor): SkillResult
    {
        $componente = $this->tarifas->buscarComponente(trim((string) ($entrada['componente'] ?? '')));

        if ($componente === null) {
            return SkillResult::error(
                'No encuentro ningún componente con ese nombre. Búscalo con buscar_tarifas y '
                . 'usa el nombre que te devuelva; no lo escribas de memoria.'
            );
        }

        $tarifa = new TravelTarifa();
        $tarifa->setComponente($componente);

        $error = $this->tarifas->aplicar($tarifa, $entrada, exigeNombre: true);

        if ($error !== null) {
            return SkillResult::error($error);
        }

        $retrato = $this->tarifas->retrato($tarifa);

        if (!(bool) ($entrada['confirmado'] ?? false)) {
            // No se ha persistido nada: sin `persist()` Doctrine no sabe que existe.
            return SkillResult::ok([
                'accion' => 'crear',
                'quedaria' => $retrato,
                'confirmado' => false,
                'instruccion' => 'ENSÉÑASELO TAL CUAL y espera su sí antes de volver a llamarme '
                    . 'con confirmado=true. Nombra las condiciones que quedan SIN LÍMITE: es lo '
                    . 'que el operador no ve venir.',
            ]);
        }

        $this->em->persist($tarifa);
        $this->em->flush();

        return SkillResult::ok([
            'accion' => 'creada',
            'tarifa' => $this->tarifas->retrato($tarifa),
            'confirmado' => true,
        ]);
    }
}
