<?php

declare(strict_types=1);

namespace App\Oweb\Service;

use DateTimeImmutable;
use Symfony\Component\Filesystem\Exception\IOExceptionInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * Servicio de utilidades generales para procesamiento de cadenas,
 * archivos y conversiones de formato Excel.
 */
class MainVariableproceso
{
    private array $mensajes = [];
    private string $projectDir;
    private Filesystem $filesystem;

    public function __construct(
        KernelInterface $kernel,
        Filesystem $filesystem
    ) {
        $this->projectDir = $kernel->getProjectDir();
        $this->filesystem = $filesystem;
    }

    /**
     * Elimina acentos y caracteres especiales latinos.
     */
    public function stripAccents(string $string): string
    {
        return strtr(
            $string,
            'àáâãäçèéêëìíîïñòóôõöùúûüýÿÀÁÂÃÄÇÈÉÊËÌÍÎÏÑÒÓÔÕÖÙÚÛÜÝ',
            'aaaaaceeeeiiiinooooouuuuyyAAAAACEEEEIIIINOOOOOUUUUY'
        );
    }

    /**
     * Prepend (agrega al inicio) texto a un archivo.
     * Crea el directorio si no existe y maneja rutas absolutas.
     *
     * @param string $relativePath Ruta relativa desde la raíz del proyecto (ej: 'debug/log.txt')
     * @param string $text Texto a agregar
     * @return bool
     */
    public function prependToFile(string $relativePath, string $text): bool
    {
        // 1. Construir ruta absoluta para evitar errores de "No such file"
        $absolutePath = $this->projectDir . '/' . ltrim($relativePath, '/');

        try {
            // 2. Obtener contenido actual (si existe)
            $oldText = '';
            if ($this->filesystem->exists($absolutePath)) {
                // Leemos todo el contenido (o puedes limitar el tamaño si prefieres la lógica antigua de 32kb)
                $oldText = file_get_contents($absolutePath);
            }

            // 3. Escribir contenido nuevo + antiguo
            // dumpFile crea los directorios automáticamente si faltan
            $this->filesystem->dumpFile($absolutePath, $text . $oldText);

            return true;
        } catch (IOExceptionInterface $e) {
            // Aquí podrías loguear el error con LoggerInterface si lo inyectaras
            return false;
        }
    }

    /**
     * Almacena mensajes en memoria durante la ejecución del request.
     * (Reemplazo de la lógica estática anterior).
     */
    public function setMensajes(string $contenido, string $tipo = 'info'): void
    {
        $this->mensajes[] = [
            'contenido' => $contenido,
            'tipo'      => $tipo,
        ];
    }

    public function getMensajes(): array
    {
        return $this->mensajes;
    }

    /**
     * Limpia una cadena de caracteres peligrosos o no deseados.
     */
    public function sanitizeString(string $str, string $with = '', array $what = []): string
    {
        // Definición de patrones a limpiar
        $patterns = array_merge($what, [
            "/[\x00-\x20]+/", // Caracteres de control ASCII
            "/[']+/", "/[(]+/", "/[)]+/", "/[-]+/", "/[+]+/",
            "/[*]+/", "/[,]+/", "/[\/]+/", "/[\\\\]+/", "/[?]+/"
        ]);

        // preg_replace acepta arrays tanto en pattern como en replacement.
        // Si 'replacement' es string, lo aplica a todos los patrones.
        $result = preg_replace($patterns, $with, $str);

        return trim($result ?? '');
    }

    /**
     * Convierte tiempos entre formato Excel y PHP.
     */
    public function excelTime(string $variable, string $tipo = 'from'): string|float
    {
        if ($variable === '') {
            return '00:00:00';
        }

        if ($tipo === 'from') {
            return $this->convertFromExcelTime($variable);
        }

        return $this->convertToExcelTime($variable);
    }

    private function convertFromExcelTime(string $variable): string
    {
        // Caso 1: Cadena con dos puntos o >= 1 (posible formato crudo HHMMSS o decimal)
        if ((!is_numeric($variable) && str_contains($variable, ':')) || (float)$variable >= 1) {
            $raw = str_replace(':', '', $variable);

            // Padding si viene como '930' -> '93000' (lógica original preservada)
            $raw = str_pad($raw, 6, '0', STR_PAD_RIGHT);

            if (strlen($raw) !== 6) {
                return $variable;
            }

            // Parsear HH:MM:SS
            return date('H:i:s', strtotime(substr($raw, 0, 2) . ':' . substr($raw, 2, 2) . ':' . substr($raw, 4, 2)));
        }

        // Caso 2: Fracción decimal de Excel (ej: 0.5 = 12:00 PM)
        if (is_numeric($variable)) {
            $val = (float)$variable * 24;
            $hours = (int)$val;

            $val = ($val - $hours) * 60;
            $minutes = (int)$val;

            $seconds = (int)round(($val - $minutes) * 60);

            return sprintf('%02d:%02d:%02d', $hours, $minutes, $seconds);
        }

        return $variable;
    }

    private function convertToExcelTime(string $variable): float|string
    {
        $clean = str_replace(':', '', $variable);
        if (strlen($clean) !== 6 || !is_numeric($clean)) {
            return $variable;
        }

        $h = (float)substr($clean, 0, 2);
        $m = (float)substr($clean, 2, 2);
        $s = (float)substr($clean, 4, 2);

        return ($h / 24) + ($m / 1440) + ($s / 86400);
    }

    /**
     * Convierte fechas entre formato Excel (serial) y PHP.
     */
    public function excelDate(int|string $variable, string $tipo = 'from'): int|string
    {
        if (empty($variable)) {
            return '';
        }

        if ($tipo === 'from') {
            if (!is_numeric($variable) && (str_contains($variable, '-') || str_contains($variable, '/'))) {
                return date('Y-m-d', strtotime(str_replace('/', '-', (string)$variable)));
            }

            if (is_numeric($variable)) {
                // Excel base date logic (1900 system)
                // Usamos DateTimeImmutable para mayor precisión que mktime
                $baseDate = new DateTimeImmutable('1899-12-30');
                // Nota: Excel tiene un bug con 1900 bisiesto, pero la lógica de mktime original era:
                // mktime(0,0,0,1, $variable-1, 1900).
                // $variable días desde 1900-01-01.

                return date('Y-m-d', mktime(0, 0, 0, 1, (int)$variable - 1, 1900));
            }

            return $variable;
        }

        // TO Excel (Logica original JD)
        // Convertir string date a Julian Day Count
        return unixtojd(strtotime($variable . ' GMT-5')) - gregoriantojd(1, 1, 1900) + 2;
    }

    public function isMultiArray(array $array): bool
    {
        return count($array) !== count($array, COUNT_RECURSIVE);
    }

    /**
     * Construye una URL a partir de sus partes (Polyfill para http_build_url).
     */
    public function buildUrl(array $parts): string
    {
        $scheme   = isset($parts['scheme']) ? $parts['scheme'] . ':' : '';
        $authority = '';

        if (isset($parts['host'])) {
            $user = $parts['user'] ?? '';
            $pass = isset($parts['pass']) ? ':' . $parts['pass'] : '';
            $auth = $user . $pass;
            $host = $parts['host'];
            $port = isset($parts['port']) ? ':' . $parts['port'] : '';

            $authority = '//' . ($auth ? "$auth@" : '') . $host . $port;
        }

        $path     = $parts['path'] ?? '';
        $query    = isset($parts['query']) ? '?' . $parts['query'] : '';
        $fragment = isset($parts['fragment']) ? '#' . $parts['fragment'] : '';

        return $scheme . $authority . $path . $query . $fragment;
    }
}