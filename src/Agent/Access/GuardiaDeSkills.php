<?php

declare(strict_types=1);

namespace App\Agent\Access;

use App\Agent\Skill\EntradaDeSkill;
use App\Agent\Skill\SkillInterface;
use App\Agent\Skill\SkillRegistry;
use App\Agent\Skill\SkillResult;
use Psr\Cache\CacheItemPoolInterface;

/**
 * El último filtro antes de ejecutar una skill. **Aquí se cierra, no se pide.**
 *
 * ### Por qué hace falta si ya filtra el registro
 *
 * {@see \App\Agent\Skill\SkillRegistry::paraActor()} decide **qué se le OFRECE** al modelo.
 * Esto decide **qué se le PERMITE ejecutar**, y no son la misma pregunta:
 *
 * - El catálogo se arma una vez por turno. Si algo lo ensancha —un canal que pasa
 *   `permitirEscritura: true` de más, una skill que se cuela por un dominio mal declarado—,
 *   no hay nada detrás.
 * - Un modelo puede inventarse el nombre de una herramienta que no se le ofreció. Hoy los
 *   adaptadores buscan por nombre en la lista permitida, así que no cuela; pero eso es una
 *   propiedad de dos archivos que alguien puede cambiar sin darse cuenta de que era el cierre.
 *
 * La regla de la casa aplicada al pie de la letra: **el prompt es una petición, el cierre es
 * código**. Que la descripción de una skill diga «pide confirmación» no impide nada.
 *
 * ### 🔒 Lo que se confirma es lo que se enseñó (17/10/2026)
 *
 * {@see self::ejecutar()} ata cada `confirmado: true` a la previsualización que vio el operador.
 * Hasta entonces el paso «previsualiza y espera el sí» dependía sólo de que el modelo lo
 * respetara, y no lo hizo: Jorge aprobó «Casita 4 de 35,40 a 24,00 en 2 noches» (ajuste `-11.40`),
 * ese turno se perdió, y en el siguiente el modelo ejecutó por su cuenta OTRA cosa —`"24"`, que
 * la skill leía como +24, y sólo las noches libres—. Nada lo frenó porque cada llamada es
 * independiente y `confirmado` es un booleano que pone el propio modelo.
 *
 * Se compara **lo que la skill ENSEÑA**, no los parámetros: «Casita 3» y «3» son la misma
 * casita y la misma previsualización, mientras que `-11.40` y `"24"` dan dos distintas. Por eso
 * cada skill de escritura tiene que previsualizar **sin efectos y de forma determinista** —ya lo
 * exigía {@see \App\Agent\Skill\SkillInterface::nivelRiesgo()}—: una previsualización con la
 * hora o un token aleatorio no casaría nunca y obligaría a confirmar dos veces.
 *
 * Y **no en el mismo turno**: lo previsualizado en este turno no se puede confirmar hasta que el
 * operador conteste ({@see PrevisualizacionesDelTurno}). Sin eso, el modelo podía enseñar y
 * aplicar en dos vueltas seguidas del mismo bucle sin que nadie lo hubiera visto.
 *
 * Ver {@see NivelRiesgo::Escritura} y docs/Mensajeria.md §11.
 */
final readonly class GuardiaDeSkills
{
    /** Cuánto vale una previsualización para confirmarla. Pasado esto, se vuelve a enseñar. */
    private const int VIGENCIA_SEGUNDOS = 1800;

    public function __construct(
        private CacheItemPoolInterface $cache,
        private PrevisualizacionesDelTurno $delTurno,
    ) {}

    /**
     * Ejecuta la skill, y si escribe, sólo lo que el operador vio y aprobó.
     *
     * ```
     * confirmado=false  → la skill previsualiza; se guarda su HUELLA por conversación y skill
     * confirmado=true   → se vuelve a previsualizar con los mismos datos y se compara la huella:
     *                       igual   → se aplica, y la huella se gasta (un sí, una escritura)
     *                       distinta, o no hubo → NO se aplica: se devuelve la previsualización
     *                                 de ahora para enseñarla y pedir el sí otra vez
     * ```
     *
     * Las de lectura pasan tal cual.
     *
     * @param array<string, mixed> $entrada
     */
    public function ejecutar(SkillInterface $skill, array $entrada, ActorInterface $actor): SkillResult
    {
        if ($skill->nivelRiesgo() !== NivelRiesgo::Escritura) {
            return $skill->ejecutar($entrada, $actor);
        }

        $clave = self::clave($skill, $actor);

        if (!(new EntradaDeSkill($entrada))->booleano('confirmado')) {
            $previa = $skill->ejecutar($entrada, $actor);

            if (!$previa->esError() && $clave !== null) {
                $this->guardar($clave, self::huella($previa));
                $this->delTurno->anotar($clave);
            }

            return $previa;
        }

        // Sin conversación ni usuario no hay dónde haber guardado nada: no se puede atar, así que
        // no se escribe. Hoy no pasa —escribir exige roles del equipo, o sea usuario—.
        if ($clave === null) {
            return SkillResult::error('No puedo aplicar este cambio: no sé de qué conversación viene la aprobación.');
        }

        // Enseñado en ESTE turno: el operador todavía no ha podido verlo, y menos aprobarlo.
        if ($this->delTurno->hubo($clave)) {
            return SkillResult::ok([
                'aplicado' => false,
                'motivo' => 'sin_aprobar_todavia',
                'importante' => 'NO SE HA APLICADO NADA. Acabas de previsualizarlo en este mismo '
                    . 'turno: el operador aún no lo ha visto. Enséñale la previsualización, espera '
                    . 'su sí y llámame con confirmado=true en el turno siguiente.',
            ]);
        }

        $ahora = $skill->ejecutar(['confirmado' => false] + $entrada, $actor);

        if ($ahora->esError()) {
            return $ahora;
        }

        $huella = self::huella($ahora);
        $item = $this->cache->getItem($clave);

        if ($item->isHit() && $item->get() === $huella) {
            $this->cache->deleteItem($clave);

            return $skill->ejecutar($entrada, $actor);
        }

        $this->guardar($clave, $huella);
        $this->delTurno->anotar($clave);

        // `array_merge` y no `+`: la previsualización ya trae su propio `motivo` (`falta_confirmacion`)
        // y con `+` ganaba el suyo, así que el modelo no se enteraba de que no se aplicó.
        return SkillResult::ok(array_merge($ahora->datos, [
            'aplicado' => false,
            'motivo' => 'confirmacion_no_coincide',
            'importante' => 'NO SE HA APLICADO NADA. Lo que me pides confirmar no es lo que el '
                . 'operador vio y aprobó (o no llegó a verlo). Estos son los datos de AHORA: '
                . 'enséñaselos tal cual, pide su sí otra vez y vuelve a llamarme con EXACTAMENTE '
                . 'los mismos datos y confirmado=true.',
        ]));
    }
    /**
     * ¿Por qué NO puede ejecutarse? `null` = adelante.
     *
     * Devuelve el motivo en texto y no un booleano porque el que se lo come es un modelo:
     * necesita saber qué decirle al usuario. «No puedes» a secas produce una disculpa vaga y
     * el usuario vuelve a intentarlo igual.
     */
    public function motivoDeBloqueo(SkillInterface $skill, ActorInterface $actor): ?string
    {
        // Se vuelve a mirar aquí a propósito, aunque el registro ya lo mirase: es barato y es el
        // único punto por el que pasa TODA ejecución, venga del catálogo o no.
        //
        // 🔥 La política ENTERA del catálogo, no sólo los roles (16/10/2026): apagada, de otro
        // negocio, de dinero en una consulta de OTA o sin su contexto. Sólo miraba los roles, y
        // por la CLI (`app:agent:skill`), que no pasa por el catálogo, se ejecutaba lo demás.
        return SkillRegistry::motivoDeExclusion($skill, $actor);
    }

    /**
     * Por conversación y skill. Sin conversación (el chat del panel), por usuario y canal: escribir
     * exige roles del equipo, así que siempre hay usuario.
     */
    private static function clave(SkillInterface $skill, ActorInterface $actor): ?string
    {
        $quien = $actor->conversacionId() !== null
            ? 'c' . $actor->conversacionId()
            : ($actor->usuario()?->getId() !== null ? 'u' . $actor->usuario()->getId() . $actor->origen() : null);

        return $quien === null ? null : 'previsualizacion_' . sha1($quien . '|' . $skill->nombre());
    }

    /** La huella de lo que la skill enseña: sus datos, en un orden fijo. */
    private static function huella(SkillResult $previa): string
    {
        $datos = $previa->datos;
        self::ordenar($datos);

        return sha1((string) json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
    }

    /** @param array<array-key, mixed> $datos */
    private static function ordenar(array &$datos): void
    {
        if (!array_is_list($datos)) {
            ksort($datos);
        }
        foreach ($datos as &$valor) {
            if (is_array($valor)) {
                self::ordenar($valor);
            }
        }
    }

    private function guardar(string $clave, string $huella): void
    {
        $item = $this->cache->getItem($clave);
        $item->set($huella);
        $item->expiresAfter(self::VIGENCIA_SEGUNDOS);
        $this->cache->save($item);
    }
}
