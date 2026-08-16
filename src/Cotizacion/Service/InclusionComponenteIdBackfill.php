<?php

declare(strict_types=1);

namespace App\Cotizacion\Service;

use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/**
 * Inyecta `componenteId` en las líneas de inclusión ya guardadas.
 *
 * ── Por qué existe ──────────────────────────────────────────────────────────
 * El proveedor que ve el cliente se lee del componente VIVO —lo resuelve el backend contra
 * el catálogo maestro al servir— y el único puente entre la línea del snapshot financiero
 * y ese componente es su id. Las propuestas anteriores al campo no lo traen, así que sin
 * esto habría que reabrir y re-guardar cada una a mano.
 *
 * ── Vive aparte del comando a propósito ─────────────────────────────────────
 * Lo llaman DOS sitios: el comando `app:cotizacion:backfill-componente-id`, para poder
 * repetirlo o probarlo con `--dry-run`, y una **migración**, para que se ejecute solo al
 * desplegar. Lo segundo es lo que importa: un paso post-despliegue que hay que recordar es
 * un paso que algún día no se hace, y el síntoma sería silencioso —propuestas que dejan de
 * mostrar el proveedor— en vez de un error que alguien vea.
 *
 * Sólo toca dos columnas `json` que ninguna entidad recalcula al guardarse y que no pasan
 * por `AutoTranslate` ni por los listeners de coherencia financiera, así que va por SQL y
 * no por el ORM. Ver la tabla de «qué entra por migración y qué por comando» en CLAUDE.md.
 *
 * Idempotente: las líneas que ya tienen `componenteId` no se tocan.
 */
final class InclusionComponenteIdBackfill
{
    private const COLUMNAS = ['clasificacion_financiera', 'clasificacion_financiera_cliente'];
    private const SECCIONES = ['incluidos', 'noIncluidos', 'cortesias', 'opcionales'];

    public function __construct(private readonly Connection $conn)
    {
    }

    /**
     * @return array{cotizaciones: int, lineas: int, huerfanas: int, ambiguas: int}
     */
    public function ejecutar(bool $dryRun = false): array
    {
        $ambiguas = 0;
        $mapa = $this->mapaDeComponentes($ambiguas);

        $cotizaciones = $this->conn->fetchAllAssociative(
            'SELECT LOWER(HEX(id)) AS id, clasificacion_financiera, clasificacion_financiera_cliente
             FROM cotizacion_cotizacion
             WHERE clasificacion_financiera IS NOT NULL
                OR clasificacion_financiera_cliente IS NOT NULL'
        );

        $tocadas = 0;
        $lineas = 0;
        $huerfanas = 0;

        foreach ($cotizaciones as $cot) {
            $cambios = [];

            foreach (self::COLUMNAS as $col) {
                if (!is_string($cot[$col]) || $cot[$col] === '') {
                    continue;
                }

                /** @var array<string, mixed>|null $json */
                $json = json_decode($cot[$col], true);
                if (!is_array($json) || !isset($json['inclusiones']) || !is_array($json['inclusiones'])) {
                    continue;
                }

                $mutado = false;

                foreach ($json['inclusiones'] as &$servicio) {
                    if (!is_array($servicio)) {
                        continue;
                    }

                    $servicioId = strtolower((string) ($servicio['servicioId'] ?? ''));

                    foreach (self::SECCIONES as $seccion) {
                        if (!isset($servicio[$seccion]) || !is_array($servicio[$seccion])) {
                            continue;
                        }

                        foreach ($servicio[$seccion] as &$linea) {
                            if (!is_array($linea) || isset($linea['componenteId'])) {
                                continue;
                            }

                            // Sólo las líneas de componente: las de ítem no tienen uno propio.
                            if (($linea['origen'] ?? '') !== 'componente') {
                                continue;
                            }

                            $clave = self::clave($servicioId, $linea['nombre'] ?? [], $linea['fecha'] ?? null);
                            $id = $mapa[$clave] ?? null;

                            if ($id === null) {
                                ++$huerfanas;
                                continue;
                            }

                            $linea['componenteId'] = $id;
                            $mutado = true;
                            ++$lineas;
                        }
                        unset($linea);
                    }
                }
                unset($servicio);

                if ($mutado) {
                    $cambios[$col] = json_encode($json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                }
            }

            if ($cambios === []) {
                continue;
            }

            ++$tocadas;

            if ($dryRun) {
                continue;
            }

            $sets = implode(', ', array_map(static fn (string $c): string => "$c = :$c", array_keys($cambios)));
            $this->conn->executeStatement(
                "UPDATE cotizacion_cotizacion SET $sets WHERE id = UNHEX(:id)",
                [...$cambios, 'id' => $cot['id']]
            );
        }

        return [
            'cotizaciones' => $tocadas,
            'lineas' => $lineas,
            'huerfanas' => $huerfanas,
            'ambiguas' => $ambiguas,
        ];
    }

    /**
     * Mapa clave natural → id de componente, saltando los grupos ambiguos.
     *
     * La clave es `(servicio, nombre en español, fecha)`, que es la identidad que la línea
     * ya lleva. **No valdría para resolver en cada render** —dos componentes homónimos el
     * mismo día del mismo servicio se pisarían sin que nadie se entere—, pero aquí sirve
     * porque se ejecuta una vez y, sobre todo, porque **lo ambiguo se salta en vez de
     * adivinarse**: si dos componentes comparten clave, NINGUNO entra en el mapa.
     *
     * Medido antes de escribirlo: 0 grupos ambiguos por esa clave, frente a 6 si se hubiera
     * usado sólo `(servicio, nombre)`.
     *
     * ⚠️ Los ids se leen en BINARIO y se convierten con `Uuid::fromBinary()`, no con
     * `HEX()`. El snapshot guarda la forma canónica CON guiones y `HEX()` devuelve 32
     * caracteres sin ellos: casarlos no coincide nunca y **no da error**. La primera
     * versión de esto dio 254 líneas sin pareja y 0 enlazadas por exactamente eso.
     *
     * @return array<string, string>
     */
    private function mapaDeComponentes(int &$ambiguas): array
    {
        $filas = $this->conn->fetchAllAssociative(
            "SELECT c.id AS id_bin,
                    c.cotservicio_id AS servicio_bin,
                    JSON_UNQUOTE(JSON_EXTRACT(c.nombre_snapshot, '$[0].content')) AS nombre,
                    DATE(c.fecha_hora_inicio) AS fecha
             FROM cotizacion_cotcomponente c"
        );

        $mapa = [];
        $chocan = [];

        foreach ($filas as $f) {
            $servicioId = self::uuid($f['servicio_bin']);
            $componenteId = self::uuid($f['id_bin']);

            if ($servicioId === null || $componenteId === null) {
                continue;
            }

            $clave = self::clave($servicioId, (string) $f['nombre'], $f['fecha']);

            if (isset($mapa[$clave])) {
                $chocan[$clave] = true;
                continue;
            }

            $mapa[$clave] = $componenteId;
        }

        foreach (array_keys($chocan) as $clave) {
            unset($mapa[$clave]);
        }

        $ambiguas = count($chocan);

        return $mapa;
    }

    /** Binario de 16 bytes → forma canónica con guiones, que es como lo guarda el snapshot. */
    private static function uuid(mixed $bin): ?string
    {
        if (!is_string($bin) || strlen($bin) !== 16) {
            return null;
        }

        return strtolower((string) Uuid::fromBinary($bin));
    }

    /**
     * La identidad que la línea ya lleva. El nombre puede venir como i18n (array) o ya
     * resuelto a texto, según de dónde se lea.
     */
    private static function clave(string $servicioId, mixed $nombre, mixed $fecha): string
    {
        if (is_array($nombre)) {
            $texto = '';
            foreach ($nombre as $n) {
                if (is_array($n) && ($n['language'] ?? '') === 'es') {
                    $texto = (string) ($n['content'] ?? '');
                    break;
                }
            }
            if ($texto === '' && isset($nombre[0]) && is_array($nombre[0])) {
                $texto = (string) ($nombre[0]['content'] ?? '');
            }
        } else {
            $texto = (string) $nombre;
        }

        $dia = '';
        if (is_string($fecha) && $fecha !== '') {
            $dia = substr($fecha, 0, 10);
        }

        return strtolower(trim($servicioId)) . '|' . strtolower(trim($texto)) . '|' . $dia;
    }
}
