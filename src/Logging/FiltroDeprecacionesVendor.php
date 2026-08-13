<?php

namespace App\Logging;

use Monolog\Handler\HandlerInterface;
use Monolog\LogRecord;

/**
 * Descarta las deprecaciones que emite el propio código de vendor/ y que, por
 * tanto, no podemos accionar desde aquí: las dispara la librería llamándose a sí
 * misma, no nuestro código llamándola a ella. Se arreglan actualizando el paquete
 * cuando su autor las resuelva, no tocando `src/`.
 *
 * Motivo: en agosto de 2026 el directorio de logs de producción llegó a 862 MB,
 * de los cuales 736 MB eran una sola deprecación de api-platform repetida
 * (`ApiProperty::getBuiltinTypes()`). Eso enterraba las deprecaciones propias,
 * que son las únicas que hay que atender antes del salto a Symfony 7.
 *
 * Ojo: esto NO silencia las deprecaciones de symfony/*. Ésas siguen llegando al
 * fichero, que es justo el punto — si vuelven a aparecer, hay que mirarlas.
 */
final class FiltroDeprecacionesVendor implements HandlerInterface
{
    /**
     * Prefijos de paquete cuyas deprecaciones se descartan. El mensaje de Symfony
     * siempre empieza por «Since <paquete> <versión>: …», así que basta comparar
     * contra ese prefijo.
     */
    private const PAQUETES_IGNORADOS = [
        'api-platform/',
        'vich/uploader-bundle',
    ];

    public function __construct(private readonly HandlerInterface $interno)
    {
    }

    public function isHandling(LogRecord $record): bool
    {
        return $this->interno->isHandling($record);
    }

    public function handle(LogRecord $record): bool
    {
        if ($this->esRuidoDeVendor($record->message)) {
            // Devolver true lo marca como consumido: no se propaga ni se escribe.
            return true;
        }

        return $this->interno->handle($record);
    }

    public function handleBatch(array $records): void
    {
        foreach ($records as $record) {
            $this->handle($record);
        }
    }

    public function close(): void
    {
        $this->interno->close();
    }

    private function esRuidoDeVendor(string $mensaje): bool
    {
        foreach (self::PAQUETES_IGNORADOS as $paquete) {
            if (str_contains($mensaje, 'Since '.$paquete)) {
                return true;
            }
        }

        return false;
    }
}
