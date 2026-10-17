<?php

declare(strict_types=1);

namespace App\Agent\Skill;

/**
 * Las protecciones del bucle de herramientas, compartidas por los tres motores (17/10/2026).
 *
 * Vivían sólo en `GoogleAIEngine`, escritas en su formato. Anthropic y DeepSeek no tenían ninguna
 * —un modelo en bucle llamaba a la misma skill hasta agotar las vueltas—, y un cuarto motor habría
 * nacido igual de desnudo. Lo que es del bucle va aquí; lo que es del formato de cada API (cómo se
 * devuelve un resultado, cómo se pide la respuesta sin herramientas) se queda en cada motor.
 *
 * Un objeto POR TURNO: lleva estado (las llamadas servidas, la racha de fallos).
 *
 * ### Las tres protecciones
 *
 * 1. **La misma llamada no se repite.** Misma skill con los mismos argumentos: el resultado sería
 *    idéntico —ya está en el hilo— y si la skill escribe, escribiría dos veces. Se le contesta
 *    {@see self::respuestaRepetida()}, que es lo único que le saca del bucle.
 * 2. **Racha de fallos.** `MAX_FALLOS_SEGUIDOS` vueltas en las que TODO lo que se pidió falló (o
 *    era repetido) → se cierra. Un fallo suelto es normal y se corrige solo; la racha no.
 * 3. **Cierre sin herramientas.** Al llegar al tope, o con la racha, el motor le pide la respuesta
 *    final prohibiéndole llamar a nada más, con {@see self::ORDEN_DE_CIERRE} en el hilo. Sin la
 *    orden, `mode: NONE` sólo devolvió una vez 49 tokens y ni una palabra (12/10/2026).
 *
 * ⚠️ En la ÚLTIMA vuelta no se ejecuta nada: ejecutar sin poder volver a llamar significa que una
 * skill de escritura escribe y su resultado se tira. Ver {@see self::esLaUltima()}.
 *
 * Ver docs/Agent.md §5.5.
 */
final class ControlDeVueltas
{
    /** Vueltas de herramientas por turno, en los tres motores. */
    public const int MAX_VUELTAS = 8;

    /** Vueltas seguidas en las que todo falló antes de cerrar. */
    public const int MAX_FALLOS_SEGUIDOS = 3;

    /** Lo que se le dice al modelo cuando ya no puede consultar nada más. */
    public const string ORDEN_DE_CIERRE = 'Ya no puedes usar más herramientas. Contesta AHORA, en '
        . 'texto y en el idioma de la conversación, con lo que hayas averiguado. Si te falta un '
        . 'dato, dilo con naturalidad y que se lo confirma el equipo; no inventes.';

    /** @var array<string, true> skill|argumentos ya ejecutadas en este turno */
    private array $servidas = [];

    private int $fallosSeguidos = 0;

    /** ¿Todo lo de la vuelta en curso falló? Empieza en `true`: una vuelta de sólo repetidas es un fallo. */
    private bool $todoFalloEnLaVuelta = true;

    public static function esLaUltima(int $vuelta): bool
    {
        return $vuelta >= self::MAX_VUELTAS - 1;
    }

    /**
     * ¿Ya se ejecutó esta llamada en el turno? Si no, queda apuntada: la siguiente igual sí lo es.
     *
     * @param array<string, mixed> $argumentos
     */
    public function yaServida(string $skill, array $argumentos): bool
    {
        $huella = $skill . '|' . RastroDeSkill::argumentos($argumentos);

        if (isset($this->servidas[$huella])) {
            return true;
        }

        $this->servidas[$huella] = true;

        return false;
    }

    /** @return array{error_repetida: string} */
    public static function respuestaRepetida(): array
    {
        return ['error_repetida' => 'Ya llamaste a esta herramienta con estos mismos datos y su '
            . 'respuesta está más arriba. No la repitas: contesta con lo que tienes o pregunta '
            . 'qué falta.'];
    }

    /**
     * Apunta el resultado de una llamada EJECUTADA de la vuelta en curso.
     *
     * @param array<array-key, mixed> $respuesta
     */
    public function registrar(array $respuesta): void
    {
        $this->todoFalloEnLaVuelta = $this->todoFalloEnLaVuelta && RastroDeSkill::fueFallo($respuesta);
    }

    /** Lo mismo con el JSON que devuelven los adaptadores de Anthropic y DeepSeek. */
    public function registrarJson(string $respuesta): void
    {
        $leida = json_decode($respuesta, true);

        $this->registrar(is_array($leida) ? $leida : ['error' => 'respuesta ilegible']);
    }

    /**
     * Cierra la vuelta: el motivo para cerrar sin herramientas, o `null` para seguir.
     *
     * Una vuelta en la que algo salió bien reinicia la cuenta: lo que se persigue es la RACHA.
     */
    public function finDeVuelta(): ?string
    {
        $this->fallosSeguidos = $this->todoFalloEnLaVuelta ? $this->fallosSeguidos + 1 : 0;
        $this->todoFalloEnLaVuelta = true;

        return $this->fallosSeguidos >= self::MAX_FALLOS_SEGUIDOS
            ? sprintf('%d vueltas seguidas sin que ninguna herramienta contestara', $this->fallosSeguidos)
            : null;
    }

    public static function motivoDelTope(): string
    {
        return sprintf('tope de %d vueltas de herramientas', self::MAX_VUELTAS);
    }
}
