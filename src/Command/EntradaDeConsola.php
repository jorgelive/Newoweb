<?php

declare(strict_types=1);

namespace App\Command;

use App\Dto\Lee;
use Symfony\Component\Console\Exception\InvalidArgumentException;

/**
 * Lee un argumento u opción de consola **sabiendo de qué tipo es**.
 *
 * `InputInterface::getArgument()`/`getOption()` devuelven `mixed`, y es la verdad: según cómo se
 * declaró, sale texto, `null`, `bool` o una lista. Lo que se hacía con eso era un cast a ciegas
 * —`max(1, (int) $input->getOption('limite'))`—, y con él `--limite=diez` no daba error: se leía
 * `0`, el `max()` lo subía a `1`, y el comando revisaba UNA reserva diciendo que había terminado.
 *
 * Es la misma idea que `App\Service\Config\Parametro`: no añade comportamiento con una entrada
 * buena —un número en texto sigue siendo ese número—; con una mala, **falla con el nombre de la
 * opción** en vez de seguir con un cero. `Application` de Symfony pinta la excepción como el resto
 * de errores de uso y sale con código 1.
 *
 * ⚠️ No convierte los comandos en invocables con `#[Argument]`/`#[Option]`: hay crons de
 * producción que los llaman, y cambiar la firma de todos es otra decisión. Ver
 * `docs/TiposDeFrontera.md`.
 */
final class EntradaDeConsola
{
    /**
     * Texto que Symfony garantiza: argumento `REQUIRED` u opción con valor por defecto. Que no lo sea
     * es un fallo de la declaración del comando, no del que lo llama.
     */
    public static function texto(mixed $valor, string $nombre): string
    {
        if (!is_string($valor)) {
            throw new InvalidArgumentException(sprintf('«%s» tenía que ser texto y es %s.', $nombre, get_debug_type($valor)));
        }

        return $valor;
    }

    /** Texto que puede no venir (argumento `OPTIONAL`, opción sin valor por defecto). */
    public static function textoOpcional(mixed $valor, string $nombre): ?string
    {
        return $valor === null ? null : self::texto($valor, $nombre);
    }

    /** Un número entero, que en consola llega siempre como texto («25»). */
    public static function entero(mixed $valor, string $nombre): int
    {
        $entero = Lee::entero($valor);

        if ($entero === null) {
            throw new InvalidArgumentException(sprintf(
                '«%s» tiene que ser un número entero; llegó %s.',
                $nombre,
                is_string($valor) ? sprintf('«%s»', $valor) : get_debug_type($valor),
            ));
        }

        return $entero;
    }

    public static function enteroOpcional(mixed $valor, string $nombre): ?int
    {
        return $valor === null ? null : self::entero($valor, $nombre);
    }

    /**
     * Un argumento `IS_ARRAY` o una opción `VALUE_IS_ARRAY`: Symfony da siempre una lista de textos.
     *
     * @return list<string>
     */
    public static function textos(mixed $valor, string $nombre): array
    {
        if (!is_array($valor)) {
            throw new InvalidArgumentException(sprintf('«%s» tenía que ser una lista y es %s.', $nombre, get_debug_type($valor)));
        }

        return array_values(array_map(static fn (mixed $v): string => self::texto($v, $nombre), $valor));
    }
}
