<?php

declare(strict_types=1);

namespace App\Agent\Skill\Pms;

use App\Agent\Access\ActorInterface;
use App\Agent\Access\NivelRiesgo;
use App\Agent\Skill\SkillDefinition;
use App\Agent\Skill\SkillInterface;
use App\Agent\Skill\SkillParameter;
use App\Agent\Skill\SkillResult;
use App\Pms\Entity\PmsEventoCalendario;
use App\Security\Roles;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Marca la salida tardía o la entrada temprana de una estancia. **Escribe.**
 *
 * 🔗 Último eslabón de la cadena, y el primero que toca datos. Escribe **un booleano** — pero
 * ese booleano dispara tres cosas más al hacer flush, y las tres se enumeran en
 * {@see self::consecuencias()} para que la previsualización no mienta por omisión.
 *
 * ### ⚠️ No uses RegistrarCargoSkill después de ésta
 *
 * `PmsCargosAutomaticosService::sincronizarExtras()` abre sola la línea «Salida tardía (noche
 * bloqueada) · Casita N» con importe **0.00**, a la espera de que el operador la valore: cuánto
 * vale salir más tarde se negocia caso por caso y sugerir un precio sería peor que no poner
 * ninguno. Añadir un cargo con `registrar_cargo` dejaría **dos conceptos iguales** en la cuenta
 * y el operador no sabría cuál es el bueno. El importe se pone en la línea que ya existe.
 *
 * ### Por qué marca un flag y no mueve `fin`
 *
 * `PmsExtensionEstanciaService` reacciona al flag creando un evento de extensión que bloquea
 * la noche y viaja al canal como `black`. Mover `fin` fue el primer intento del proyecto y se
 * descartó por dos motivos (§7.1.b de PmsBeds24ReservasSync): no servía para las OTA —cuyas
 * fechas nunca se envían— y no protegía del propio PMS, que seguía vendiendo la noche.
 *
 * Por eso esto SÍ funciona en reservas de OTA, mientras que mover fechas está prohibido.
 *
 * ### ⚠️ La confirmación depende del modelo, no del código
 *
 * Con `confirmado: false` la skill devuelve la previsualización y no escribe. Pero **nada
 * impide llamarla con `true` a la primera**: `confirmado` es un parámetro más y el servidor no
 * recuerda si hubo previsualización. Ver §11.1 de docs/Mensajeria.md.
 */
final readonly class AplicarCambioHorarioSkill implements SkillInterface
{
    public function __construct(
        private EntityManagerInterface $em
    ) {}

    public function nombre(): string
    {
        return 'aplicar_cambio_horario';
    }

    public function definicion(): SkillDefinition
    {
        return new SkillDefinition(
            descripcion: 'Marca la salida tardía o la entrada temprana de una estancia. Hace '
                . 'SÓLO esas dos cosas: NO mueve fechas, no cambia de casita, no alarga la '
                . 'estancia un día más. Si te piden mover un día, di que hay que hacerlo en el '
                . 'calendario del panel y no llames a esta skill. MODIFICA DATOS: antes de '
                . 'llamarla con confirmado=true, dile al usuario exactamente qué vas a hacer '
                . '(huésped, casita, qué se marca) y espera su sí. Si la llamas con '
                . 'confirmado=false te devuelve la previsualización sin tocar nada. Necesita '
                . 'el evento_id de buscar_estancias_de_reserva, y conviene comprobar antes con '
                . 'evaluar_cambio_horario que está permitido. LOCALIZA PRIMERO A QUIÉN: si te '
                . 'dicen «el de la casita 2», usa consultar_ocupacion con la fecha de hoy para '
                . 'saber quién es y con qué evento_id, y confirma el nombre con el operador. '
                . 'ENSEÑA LA PREVISUALIZACIÓN ENTERA: la respuesta trae «que_va_a_pasar» con '
                . 'TODAS las consecuencias —el bloqueo que sale a los portales, la línea de '
                . 'cargo que se abre—. Léeselas una a una al operador, no las resumas, y '
                . 'termina con la pregunta de pregunta_aprobacion.',
            parametros: [
                SkillParameter::texto('evento_id', 'Identificador de la estancia.'),
                SkillParameter::texto('cambio', '"salida_tardia" o "entrada_temprana".'),
                SkillParameter::booleano('confirmado', 'true SÓLO después de que el usuario '
                    . 'haya confirmado explícitamente. false para previsualizar.'),
            ],
        );
    }

    public function rolesRequeridos(): array
    {
        return [Roles::RESERVAS_WRITE];
    }

    public function nivelRiesgo(): NivelRiesgo
    {
        return NivelRiesgo::Escritura;
    }

    public function ejecutar(array $entrada, ActorInterface $actor): SkillResult
    {
        $eventoId = trim((string) ($entrada['evento_id'] ?? ''));
        $cambio = strtolower(trim((string) ($entrada['cambio'] ?? '')));
        $confirmado = filter_var($entrada['confirmado'] ?? false, FILTER_VALIDATE_BOOL);

        if (!Uuid::isValid($eventoId)) {
            return SkillResult::error('El evento_id no es válido.');
        }

        if (!in_array($cambio, ['salida_tardia', 'entrada_temprana'], true)) {
            return SkillResult::error('El cambio debe ser "salida_tardia" o "entrada_temprana".');
        }

        $evento = $this->em->getRepository(PmsEventoCalendario::class)->find($eventoId);
        if ($evento === null) {
            return SkillResult::error('No existe ninguna estancia con ese identificador.');
        }

        $esSalida = $cambio === 'salida_tardia';
        $yaMarcado = $esSalida ? $evento->isSalidaTardia() : $evento->isEntradaTemprana();

        $resumen = [
            'evento_id' => $eventoId,
            'huesped' => trim(
                ($evento->getReserva()?->getNombreCliente() ?? '') . ' ' .
                ($evento->getReserva()?->getApellidoCliente() ?? '')
            ),
            'casita' => $evento->getPmsUnidad()?->getNombre() ?? '—',
            'localizador' => $evento->getReserva()?->getLocalizador(),
            'cambio' => $cambio,
            'es_ota' => $evento->isOta(),
            'canal' => $evento->getReserva()?->getChannel()?->getNombre(),
            'entrada_actual' => $evento->getInicio()?->format('Y-m-d H:i'),
            'salida_actual' => $evento->getFin()?->format('Y-m-d H:i'),
        ];

        if ($yaMarcado) {
            return SkillResult::ok($resumen + [
                'aplicado' => false,
                'motivo' => 'ya_estaba_marcado',
                'mensaje' => 'Esta estancia ya tenía ese horario marcado. No se ha cambiado nada.',
            ]);
        }

        if (!$confirmado) {
            return SkillResult::ok($resumen + [
                'aplicado' => false,
                'motivo' => 'falta_confirmacion',
                // Las tres consecuencias, enumeradas. Dos de ellas —el bloqueo que sale al
                // canal y la línea de cargo a cero— las provocan servicios de más abajo al
                // hacer flush, no esta skill: si no se nombran aquí, el operador aprueba una
                // cosa y ocurren tres.
                'que_va_a_pasar' => $this->consecuencias($evento, $esSalida),
                'pregunta_aprobacion' => '¿Apruebas el cambio?',
                'previsualizacion' => sprintf(
                    'La estancia de %s en %s (%s) quedará marcada con %s. Enséñale al operador '
                    . 'la lista de «que_va_a_pasar» entera antes de pedirle el sí.',
                    $resumen['huesped'] !== '' ? $resumen['huesped'] : 'el huésped',
                    $resumen['casita'],
                    $resumen['localizador'] ?? 'sin localizador',
                    $esSalida ? 'salida tardía' : 'entrada temprana'
                ),
            ]);
        }

        // Al hacer flush pasan tres cosas que esta skill NO orquesta, sólo dispara al marcar:
        // PmsExtensionEstanciaService crea el evento de extensión, el push lo manda al canal
        // como bloqueo, y PmsCargosAutomaticosService abre la línea de cargo a 0.00.
        // Están enumeradas en consecuencias() para que la previsualización no mienta por
        // omisión.
        if ($esSalida) {
            $evento->setSalidaTardia(true);
        } else {
            $evento->setEntradaTemprana(true);
        }

        $this->em->flush();

        return SkillResult::ok($resumen + [
            'aplicado' => true,
            'que_ha_pasado' => $this->consecuencias($evento, $esSalida),
            'mensaje' => sprintf(
                'Marcada la %s. Se ha creado la extensión que bloquea esa noche.',
                $esSalida ? 'salida tardía' : 'entrada temprana'
            ),
            // NO se sugiere registrar_cargo: PmsCargosAutomaticosService ya ha creado la línea
            // «Salida tardía (noche bloqueada) · Casita N» a 0.00. Añadir otra con
            // registrar_cargo dejaría dos conceptos iguales en la cuenta, y el operador no
            // sabría cuál es el bueno. Valorar la que existe se hace en el panel.
            'siguiente_paso_sugerido' => 'Ya se ha creado la línea del cargo con importe 0.00 '
                . 'para que la valore el operador. NO uses registrar_cargo para esto: '
                . 'duplicaría el concepto. El importe se le pone a esa línea desde el panel '
                . 'financiero de la reserva.',
        ]);
    }

    /**
     * Todo lo que se dispara al marcar, con nombre y apellidos.
     *
     * La skill escribe **un booleano**; lo demás lo hacen listeners y servicios al hacer flush.
     * Enumerarlo aquí es lo que separa «¿apruebas marcar la salida tardía?» de «¿apruebas que
     * la casita deje de venderse esa noche en todos los portales?» — que es la misma acción y
     * la segunda es la que el operador necesita oír.
     *
     * @return list<string>
     */
    private function consecuencias(PmsEventoCalendario $evento, bool $esSalida): array
    {
        $casita = $evento->getPmsUnidad()?->getNombre() ?? 'la casita';
        $noche = $esSalida ? 'la noche del día de salida' : 'la noche anterior a la llegada';
        $concepto = $esSalida ? 'Salida tardía (noche bloqueada)' : 'Entrada temprana (noche bloqueada)';

        $lista = [
            sprintf(
                'Se marca la %s en la estancia. Las horas de entrada y salida NO cambian: '
                . 'esta acción no fija una hora concreta.',
                $esSalida ? 'salida tardía' : 'entrada temprana'
            ),
            sprintf(
                'Se crea un evento de extensión que ocupa %s en %s, así que %s deja de estar '
                . 'disponible esa noche.',
                $noche,
                $casita,
                $casita
            ),
        ];

        $canal = $evento->getReserva()?->getChannel()?->getNombre();
        $lista[] = sprintf(
            'La extensión viaja al canal como bloqueo, así que %s deja de venderse esa noche '
            . 'en TODOS los portales%s. Deshacerlo exige desmarcarlo y esperar otro push.',
            $casita,
            $canal !== null ? ' (esta reserva vino de ' . $canal . ')' : ''
        );

        $lista[] = sprintf(
            'Se abre en la cuenta una línea «%s · %s» con importe 0.00. NO cobra nada por sí '
            . 'sola: el importe se lo pone el operador desde el panel financiero.',
            $concepto,
            $casita
        );

        return $lista;
    }
}
