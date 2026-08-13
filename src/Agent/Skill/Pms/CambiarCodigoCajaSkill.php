<?php

declare(strict_types=1);

namespace App\Agent\Skill\Pms;

use App\Agent\Access\ActorInterface;
use App\Agent\Access\NivelRiesgo;
use App\Agent\Skill\SkillDefinition;
use App\Agent\Skill\SkillDominioInterface;
use App\Agent\Skill\SkillInterface;
use App\Agent\Skill\SkillParameter;
use App\Agent\Skill\SkillResult;
use App\Pms\Service\Agent\PmsFrentes;
use App\Pms\Entity\PmsEstablecimiento;
use App\Security\Roles;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Cambia el código de las cajas fuertes del alojamiento. **Escribe, y afecta a todo el mundo.**
 *
 * Hay dos cajas y no se parecen en nada salvo en el nombre:
 *
 * | | Qué guarda | Quién la abre |
 * |---|---|---|
 * | `principal` | Las **llaves** de las casitas | Los huéspedes |
 * | `secundaria` | La **recaudación** | Sólo el equipo |
 *
 * ### ⚠️ La principal la están usando ahora mismo
 *
 * Su código va en la guía (`{{ keybox_main }}`) y en los mensajes ya enviados. Cambiarlo mientras
 * hay gente alojada **los deja fuera**: el que recibió el código viejo por WhatsApp hace tres
 * días vuelve a las 23:00 y no entra. Por eso la previsualización dice cuántos huéspedes lo están
 * usando y quiénes son — el dato que decide si se cambia ahora o mañana.
 *
 * La guía web y el chat sirven el valor nuevo desde el instante del flush, así que quien la abra
 * *después* verá el correcto. El problema no es el sistema: es el papelito y el WhatsApp viejo.
 *
 * ### 💰 La secundaria no se enseña al huésped jamás
 *
 * No aparece en `consultar_codigos` ni con el actor del equipo, y aquí sólo se puede cambiar,
 * nunca leer «de paso»: la respuesta confirma el cambio sin repetir el código nuevo, para que no
 * se quede escrito en un chat que alguien reenvía.
 */
final readonly class CambiarCodigoCajaSkill implements SkillInterface, SkillDominioInterface
{
    /** Un código de caja es corto y numérico con alguna letra: 2499E. */
    private const int MAX_LARGO = 20;

    public function __construct(
        private EntityManagerInterface $em,
    ) {}

    public function nombre(): string
    {
        return 'cambiar_codigo_caja';
    }

    public function definicion(): SkillDefinition
    {
        return new SkillDefinition(
            descripcion: 'Cambia el código de una de las dos cajas fuertes. La «principal» es la '
                . 'de las LLAVES, la que abren los huéspedes y sale en su guía; la «secundaria» '
                . 'es la del DINERO y sólo la usa el equipo. MODIFICA DATOS Y AFECTA A GENTE '
                . 'ALOJADA: llama SIEMPRE primero con confirmado=false. Si es la principal, la '
                . 'respuesta te dice CUÁNTOS HUÉSPEDES la están usando ahora mismo y quiénes: '
                . 'léeselo al operador antes de pedirle el sí, porque a los que ya tienen el '
                . 'código viejo por WhatsApp hay que avisarles o se quedan fuera. Después de '
                . 'cambiarlo, ofrécele mandarles el nuevo con enviar_mensaje_huesped. NUNCA '
                . 'repitas el código de la caja secundaria en el chat.',
            parametros: [
                SkillParameter::texto('caja', '"principal" (llaves, la ven los huéspedes) o '
                    . '"secundaria" (dinero, sólo el equipo).'),
                SkillParameter::texto('codigo', 'El código nuevo.'),
                SkillParameter::booleano('confirmado', 'true SÓLO tras la aprobación explícita '
                    . 'del operador. false para previsualizar.'),
            ],
        );
    }

    /**
     * Del negocio de ALOJAMIENTO: habla de reservas, estancias, casitas o su dinero.
     *
     * Recorta el catálogo, no los permisos — ver {@see SkillDominioInterface}.
     *
     * @return list<string>
     */
    public function dominios(): array
    {
        return [PmsFrentes::NEGOCIO];
    }

    /** Sólo el equipo con permiso de escritura: deja fuera a limpieza y al huésped. */
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
        $caja = strtolower(trim((string) ($entrada['caja'] ?? '')));

        if (!in_array($caja, ['principal', 'secundaria'], true)) {
            return SkillResult::error(
                'Dime qué caja: "principal" (la de las llaves, la de los huéspedes) o '
                . '"secundaria" (la del dinero, sólo del equipo).'
            );
        }

        $codigo = trim((string) ($entrada['codigo'] ?? ''));

        if ($codigo === '') {
            return SkillResult::error('Dime el código nuevo.');
        }

        if (mb_strlen($codigo) > self::MAX_LARGO) {
            return SkillResult::error(sprintf(
                'Ese código tiene %d caracteres y el máximo son %d. ¿Seguro que es el código y no '
                . 'otra cosa?',
                mb_strlen($codigo),
                self::MAX_LARGO
            ));
        }

        $establecimientos = $this->em->getRepository(PmsEstablecimiento::class)->findAll();

        if ($establecimientos === []) {
            return SkillResult::error('No hay ningún establecimiento configurado.');
        }

        // Hoy hay uno solo. Con varios habría que preguntar cuál en vez de elegir por él.
        if (count($establecimientos) > 1) {
            return SkillResult::error(
                'Hay más de un establecimiento y esta skill no sabe elegir. Cámbialo desde el '
                . 'panel para no tocar el del sitio equivocado.'
            );
        }

        $establecimiento = $establecimientos[0];
        $esPrincipal = $caja === 'principal';

        $actual = $esPrincipal
            ? $establecimiento->getCodigoCajaPrincipal()
            : $establecimiento->getCodigoCajaSecundaria();

        if (trim((string) $actual) === $codigo) {
            return SkillResult::ok([
                'aplicado' => false,
                'motivo' => 'ya_era_ese',
                'mensaje' => 'La caja ya tenía ese código. No se ha cambiado nada.',
            ]);
        }

        $afectados = $esPrincipal ? $this->huespedesUsandola() : [];

        $resumen = array_filter([
            'establecimiento' => $establecimiento->getNombreComercial(),
            'caja' => $esPrincipal ? 'principal (llaves de los huéspedes)' : 'secundaria (dinero, interna)',
            // El código VIEJO sí se dice: es lo que hay que buscar en los mensajes ya enviados
            // para saber a quién avisar. El nuevo no se repite si es la del dinero.
            'codigo_anterior' => trim((string) $actual) !== '' ? $actual : '(no había)',
            'huespedes_usandola_ahora' => $esPrincipal ? count($afectados) : null,
            'quienes' => $afectados !== [] ? $afectados : null,
        ], static fn ($v) => $v !== null);

        if (!filter_var($entrada['confirmado'] ?? false, FILTER_VALIDATE_BOOL)) {
            return SkillResult::ok($resumen + [
                'aplicado' => false,
                'motivo' => 'falta_confirmacion',
                'que_va_a_pasar' => $this->consecuencias($esPrincipal, $afectados),
                'pregunta_aprobacion' => '¿Apruebas cambiar el código?',
            ]);
        }

        $esPrincipal
            ? $establecimiento->setCodigoCajaPrincipal($codigo)
            : $establecimiento->setCodigoCajaSecundaria($codigo);

        $this->em->flush();

        return SkillResult::ok($resumen + array_filter([
            'aplicado' => true,
            // El nuevo sólo se devuelve en la de llaves, que es la que hay que comunicar. El de
            // la caja del dinero se cambia y punto: repetirlo lo deja escrito en el chat.
            'codigo_nuevo' => $esPrincipal ? $codigo : null,
            'que_ha_pasado' => $this->consecuencias($esPrincipal, $afectados),
            'siguiente_paso_sugerido' => $afectados !== []
                ? sprintf(
                    'Hay %d huésped(es) alojado(s) con el código viejo. Ofrécele al operador '
                    . 'mandarles el nuevo con enviar_mensaje_huesped, uno por uno.',
                    count($afectados)
                )
                : null,
        ], static fn ($v) => $v !== null));
    }

    /**
     * Quién está dentro ahora mismo, que es quien tiene el código viejo.
     *
     * Se mira por DÍA y no por instante: a las 09:00 del día de salida el huésped sigue dentro
     * y aún necesita abrir la caja para devolver las llaves.
     *
     * @return list<string>
     */
    private function huespedesUsandola(): array
    {
        $sql = <<<'SQL'
            SELECT u.nombre AS casita, r.nombre_cliente, r.apellido_cliente, r.localizador
            FROM pms_evento_calendario e
            JOIN pms_unidad u   ON u.id = e.pms_unidad_id
            JOIN pms_reserva r  ON r.id = e.reserva_id
            WHERE e.estado_id IN ('pendiente', 'confirmada', 'requerimiento')
              AND e.evento_origen_id IS NULL
              AND DATE(e.inicio) <= CURDATE()
              AND DATE(e.fin)    >= CURDATE()
            ORDER BY u.nombre
        SQL;

        $filas = $this->em->getConnection()->executeQuery($sql)->fetchAllAssociative();

        return array_map(
            static fn (array $f): string => sprintf(
                '%s — %s (%s)',
                $f['casita'],
                trim(($f['nombre_cliente'] ?? '') . ' ' . ($f['apellido_cliente'] ?? '')),
                $f['localizador'] ?? '?'
            ),
            $filas
        );
    }

    /**
     * @param list<string> $afectados
     * @return list<string>
     */
    private function consecuencias(bool $esPrincipal, array $afectados): array
    {
        if (!$esPrincipal) {
            return [
                'Se cambia el código de la caja del DINERO. No sale en ninguna guía ni mensaje a '
                . 'huéspedes: sólo lo usa el equipo.',
                'Avisa a quien recauda o no podrá abrirla. Esta skill no manda ese aviso.',
            ];
        }

        $lista = [
            'La guía del huésped y el chat empiezan a dar el código NUEVO desde ya: quien la '
            . 'abra a partir de ahora verá el correcto.',
        ];

        if ($afectados === []) {
            $lista[] = 'Ahora mismo NO hay nadie alojado, así que no dejas a nadie fuera. Es el '
                . 'mejor momento para cambiarlo.';

            return $lista;
        }

        $lista[] = sprintf(
            '⚠️ Hay %d huésped(es) DENTRO que ya recibieron el código viejo por WhatsApp o correo. '
            . 'Esos mensajes NO se actualizan solos: si no les avisas, vuelven de noche y no '
            . 'pueden coger las llaves.',
            count($afectados)
        );

        $lista[] = 'Los que llegan a partir de ahora recibirán el nuevo sin que hagas nada.';

        return $lista;
    }
}
