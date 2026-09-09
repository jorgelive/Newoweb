<?php

declare(strict_types=1);

namespace App\Agent\Vision;

use App\Agent\Provider\Google\GoogleAIClient;
use JsonException;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Lee imágenes con Gemini.
 *
 * ⚠️ **No hace falta tocar `GoogleAIClient`**: `generarContenido()` recibe el cuerpo crudo, así
 * que `inline_data` y `responseSchema` entran tal cual. Es la ventaja de que el cliente no
 * interprete nada.
 *
 * 🔑 **`responseSchema` en vez de pedir JSON en el prompt.** Pedirlo en prosa devuelve JSON casi
 * siempre — y ese «casi» es el problema: un día llega con ```json delante y el `json_decode` falla
 * con un documento que se leyó bien. Con el esquema, la forma la garantiza la API.
 */
final readonly class GoogleLectorDeImagen implements LectorDeImagenInterface
{
    /** Formatos que Gemini acepta en `inline_data`. Un `.doc` aquí es un error, no un intento. */
    private const ADMITIDOS = ['image/webp', 'image/jpeg', 'image/png', 'image/heic', 'image/heif', 'application/pdf'];

    /**
     * ⚠️ 20 MB es el tope de `inline_data`; por encima hay que subir el fichero aparte. Los
     * documentos de aquí pesan ~430 KB tras el filtro `documento_identidad`, así que el tope no
     * se roza — pero un PDF de veinte páginas sí lo rozaría, y el error de Google no dice cuál
     * es el límite.
     */
    private const TOPE_BYTES = 20 * 1024 * 1024;

    public function __construct(
        private GoogleAIClient $google,
        private LoggerInterface $logger,
        private string $modelo,
    ) {}

    public function nombre(): string
    {
        return 'google';
    }

    public function estaConfigurado(): bool
    {
        return $this->google->estaConfigurado();
    }

    public function leer(string $bytes, string $mime, string $instruccion, array $esquema): array
    {
        if (!in_array($mime, self::ADMITIDOS, true)) {
            throw new RuntimeException(sprintf('No se puede leer un %s: admitidos %s.', $mime, implode(', ', self::ADMITIDOS)));
        }

        if (strlen($bytes) > self::TOPE_BYTES) {
            throw new RuntimeException(sprintf('El fichero pesa %d MB y el tope son 20 MB.', intdiv(strlen($bytes), 1024 * 1024)));
        }

        $modelo = $this->modelo !== '' ? $this->modelo : $this->google->modelo();
        $cronometro = microtime(true);

        $datos = $this->google->generarContenido($modelo, [
            'contents' => [[
                'role' => 'user',
                'parts' => [
                    // La imagen ANTES de la instrucción: es lo que recomienda Google cuando hay
                    // una sola imagen, y con la instrucción delante se han visto respuestas que
                    // describen la imagen en vez de rellenar el esquema.
                    ['inline_data' => ['mime_type' => $mime, 'data' => base64_encode($bytes)]],
                    ['text' => $instruccion],
                ],
            ]],
            'generationConfig' => [
                'responseMimeType' => 'application/json',
                'responseSchema' => $esquema,
                // 🔥 Cero. Esto no es redacción: dos lecturas del mismo pasaporte tienen que dar
                // el mismo número, o no se puede comparar una con otra ni repetir un fallo.
                'temperature' => 0.0,
            ],
        ]);

        $uso = is_array($datos['usageMetadata'] ?? null) ? $datos['usageMetadata'] : [];
        $this->logger->info(sprintf(
            'Visión (google): %s · %.1f s · %s · %d KB · entrada %d · salida %d tokens.',
            $modelo,
            microtime(true) - $cronometro,
            $mime,
            intdiv(strlen($bytes), 1024),
            (int) ($uso['promptTokenCount'] ?? 0),
            (int) ($uso['candidatesTokenCount'] ?? 0),
        ));

        $texto = $datos['candidates'][0]['content']['parts'][0]['text'] ?? null;
        if (!is_string($texto) || trim($texto) === '') {
            // Sin candidatos casi siempre es un filtro de seguridad: un documento de identidad
            // con una cara dentro los roza de vez en cuando, y el motivo viene en la respuesta.
            $motivo = $datos['candidates'][0]['finishReason'] ?? $datos['promptFeedback']['blockReason'] ?? 'sin detalle';
            throw new RuntimeException(sprintf('Google no devolvió lectura (%s).', is_string($motivo) ? $motivo : 'sin detalle'));
        }

        try {
            $leido = json_decode($texto, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException('Google devolvió algo que no es JSON: ' . $e->getMessage(), 0, $e);
        }

        if (!is_array($leido)) {
            throw new RuntimeException('Google devolvió un JSON que no es un objeto.');
        }

        /** @var array<string, mixed> $leido */
        return $leido;
    }
}
