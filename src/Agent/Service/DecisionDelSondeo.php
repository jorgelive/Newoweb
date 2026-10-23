<?php

declare(strict_types=1);

namespace App\Agent\Service;

use App\Agent\Conversation\MotivoDefinitivo;
use App\Agent\Conversation\FalloDelMotor;

/**
 * Qué hacer con el resultado de la comprobación periódica del motor de IA (17/10/2026).
 *
 * El aviso de motor caído (`VigilanteDelMotor::motorCaido()`) sólo saltaba cuando un HUÉSPED ya
 * había chocado con el fallo. El 16–17/10/2026 se agotaron los créditos de Google y no lo supo
 * nadie: no entró ningún mensaje en ese rato, y se descubrió por casualidad. La comprobación
 * (`app:agent:vigilar-motor`, cada 30 minutos) se adelanta al primer huésped.
 *
 * ### Sin spam: un aviso por incidente
 *
 * Jorge: «que no me spamee cada 30 minutos». Se avisa al CAMBIAR de estado, no en cada sondeo:
 *
 * | Antes | Ahora | Qué se hace |
 * |---|---|---|
 * | bien | falla, y es definitivo (créditos, clave) | aviso de caída, ya |
 * | bien | falla por otra cosa | nada la primera vez: un corte de red no es un incidente |
 * | falló una vez | vuelve a fallar | aviso de caída |
 * | ya avisado | sigue fallando | nada (el recordatorio sale a las 24 h, cuando caduca la marca) |
 * | avisado (por el sondeo o por un turno de huésped) | responde | aviso de recuperación, una vez |
 *
 * Pura, sin caché ni red, para que la regla se pueda probar: el estado lo guarda quien llama.
 */
final class DecisionDelSondeo
{
    public const string NADA = 'nada';
    public const string AVISAR_CAIDA = 'caida';
    public const string AVISAR_RECUPERACION = 'recuperacion';

    /** Fallos seguidos de un error que no es definitivo antes de dar el motor por caído. */
    public const int FALLOS_PARA_AVISAR = 2;

    /**
     * @param FalloDelMotor|null $fallo  El fallo si el sondeo falló; `null` si respondió.
     * @param int         $fallosPrevios Sondeos fallidos seguidos hasta ahora.
     * @param bool        $yaAvisado    ¿Se avisó ya de esta caída (y no ha caducado la marca)?
     * @param bool        $avisoDeTurno ¿Avisó un turno de huésped en la última hora?
     *
     * @return array{accion: self::NADA|self::AVISAR_CAIDA|self::AVISAR_RECUPERACION, fallos: int}
     */
    public static function tras(?FalloDelMotor $fallo, int $fallosPrevios, bool $yaAvisado, bool $avisoDeTurno): array
    {
        if ($fallo === null) {
            return [
                'accion' => $yaAvisado || $avisoDeTurno ? self::AVISAR_RECUPERACION : self::NADA,
                'fallos' => 0,
            ];
        }

        $fallos = $fallosPrevios + 1;

        if ($yaAvisado) {
            return ['accion' => self::NADA, 'fallos' => $fallos];
        }

        return [
            'accion' => $fallo->esDefinitivo() || $fallos >= self::FALLOS_PARA_AVISAR
                ? self::AVISAR_CAIDA
                : self::NADA,
            'fallos' => $fallos,
        ];
    }

    /**
     * Una ronda con VARIOS motores: los de los tres tramos de potencia (22/10/2026).
     *
     * Hasta entonces se sondeaba sólo el motor por defecto (Anthropic) y el tramo bajo iba a Gemini:
     * sus créditos se agotaron y la única alarma posible era la del primer huésped que chocara.
     * Cada motor lleva su propio estado —se cae y se recupera por su cuenta— con la tabla de
     * {@see tras()}.
     *
     * ⚠️ **El aviso de un TURNO de huésped no dice qué motor falló** (`motorCaido()` sólo tiene el
     * mensaje del proveedor). Por eso no se le pasa a ningún motor: con dos, uno sano anunciaría la
     * «recuperación» de la avería del otro. Ese aviso se da por resuelto cuando responden TODOS, y
     * sólo se dice si no lo dijo ya la recuperación de algún motor en esta misma ronda.
     *
     * @param array<string, FalloDelMotor|null> $fallos motor => fallo, o `null` si respondió.
     * @param array<string, int>         $previos  motor => sondeos fallidos seguidos.
     * @param array<string, bool>        $avisados motor => ¿se avisó ya de su caída?
     *
     * @return array{
     *     motores: array<string, array{accion: self::NADA|self::AVISAR_CAIDA|self::AVISAR_RECUPERACION, fallos: int}>,
     *     todosResponden: bool,
     *     recuperarTurno: bool,
     * }
     */
    public static function deLaRonda(array $fallos, array $previos, array $avisados, bool $avisoDeTurno): array
    {
        $motores = [];
        $todos = true;
        $algunaRecuperacion = false;

        foreach ($fallos as $motor => $fallo) {
            $motores[$motor] = self::tras($fallo, $previos[$motor] ?? 0, $avisados[$motor] ?? false, false);
            $todos = $todos && $fallo === null;
            $algunaRecuperacion = $algunaRecuperacion || $motores[$motor]['accion'] === self::AVISAR_RECUPERACION;
        }

        return [
            'motores' => $motores,
            'todosResponden' => $todos,
            'recuperarTurno' => $todos && $avisoDeTurno && !$algunaRecuperacion,
        ];
    }

    // ⚠️ Aquí vivía `esDefinitivo(string)`, una expresión regular sobre el TEXTO del error, y se
    // borró el 23/10/2026: lo decide el cliente de cada proveedor, con el código HTTP
    // (`FalloDelMotor::$definitivo`). Ver la cabecera de `FalloDelMotor`.

    /** Qué tiene que hacer quien lee el aviso, si se sabe. */
    public static function pista(string $motor, FalloDelMotor $fallo): string
    {
        return match ($fallo->definitivo) {
            MotivoDefinitivo::CREDITOS => sprintf('Se han agotado los créditos de «%s»: hay que recargarlos.', $motor),
            MotivoDefinitivo::CLAVE => sprintf('«%s» rechaza la clave: revisa la del servidor (.env.local).', $motor),
            null => '',
        };
    }
}
