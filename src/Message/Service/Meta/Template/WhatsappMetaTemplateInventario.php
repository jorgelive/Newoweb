<?php

declare(strict_types=1);

namespace App\Message\Service\Meta\Template;

use App\Exchange\Entity\ExchangeEndpoint;
use App\Exchange\Entity\MetaConfig;
use App\Exchange\Service\Client\WhatsappMetaClient;
use App\Message\Entity\MessageTemplate;
use Doctrine\ORM\EntityManagerInterface;
use RuntimeException;

/**
 * Lo que Meta tiene de verdad, enfrentado a lo que tenemos aquí.
 *
 * ── Por qué hace falta ──────────────────────────────────────────────────────
 * El panel enseña el estado de cada plantilla (`APPROVED`, `PENDING`…) leyendo el JSON local,
 * y ese JSON **se escribe a mano tan fácil como lo escribe el sincronizador**. Cuando las dos
 * cosas no coinciden, lo que se ve es un estado inventado con toda la pinta de ser real:
 *
 * - `menu_tours` llevaba desde marzo en `PENDING` en los siete idiomas. En Meta **no existía**:
 *   nunca se envió, porque le faltaba el «Nombre en Meta» y la subida mandaba `name: null`.
 *   Nadie podía aprobarla y nadie tenía forma de saberlo desde el panel.
 * - `WELCOME_BOOKING_META` apareció sola un 5 de abril: Meta tenía `welcome_booking`, ninguna
 *   fila local reclamaba ese nombre —la buena había pasado a `welcome_booking_command`— y el
 *   sincronizador hizo lo único que sabe hacer, fabricarle una fila.
 *
 * Los dos casos se ven de un vistazo en esta lista y de ninguna otra forma sin abrir la consola
 * de Meta. Por eso vive en el panel y no en un comando.
 *
 * Es **solo lectura**: un `GET` a la Graph API. No escribe en la base de datos ni en Meta.
 */
final readonly class WhatsappMetaTemplateInventario
{
    /**
     * La de Meta para probar la API, que no es nuestra y no queremos ver.
     * El sincronizador la descarta con este mismo nombre.
     */
    private const string PLANTILLA_DE_EJEMPLO = 'hello_world';

    public function __construct(
        private EntityManagerInterface $em,
        private WhatsappMetaClient $metaClient,
    ) {}

    /**
     * @return array{
     *     enMeta: list<array{nombre: string, idiomas: list<array{codigo: string, estado: string}>, local: ?string, reclamada: bool}>,
     *     soloLocales: list<array{code: string, nombre: string, nombreMeta: ?string}>,
     *     total: int
     * }
     */
    public function listar(): array
    {
        $config = $this->em->getRepository(MetaConfig::class)->findOneBy(['activo' => true]);

        if (!$config) {
            throw new RuntimeException('No hay ninguna configuración activa de Meta WhatsApp en el sistema.');
        }

        $endpoint = $this->em->getRepository(ExchangeEndpoint::class)->findOneBy(['accion' => 'FETCH_META_TEMPLATES']);

        if (!$endpoint) {
            throw new RuntimeException('No se encontró el endpoint con acción FETCH_META_TEMPLATES.');
        }

        $respuesta = $this->metaClient->fetchTemplates($config, $endpoint);
        $filas = $respuesta['data'] ?? [];

        /** @var array<string, list<array{codigo: string, estado: string}>> $porNombre */
        $porNombre = [];

        foreach ($filas as $fila) {
            $nombre = (string) ($fila['name'] ?? '');

            if ($nombre === '' || $nombre === self::PLANTILLA_DE_EJEMPLO) {
                continue;
            }

            $porNombre[$nombre][] = [
                'codigo' => (string) ($fila['language'] ?? '?'),
                'estado' => strtoupper((string) ($fila['status'] ?? '?')),
            ];
        }

        ksort($porNombre);

        // Quién reclama cada nombre. Un nombre sin dueño es una plantilla que existe en Meta y
        // que aquí nadie usa: o alguien la creó en la consola, o es una gemela.
        $locales = $this->em->getRepository(MessageTemplate::class)->findAll();
        $dueños = [];

        foreach ($locales as $local) {
            $nombreMeta = $local->getWhatsappMetaName();

            if (is_string($nombreMeta) && $nombreMeta !== '') {
                $dueños[$nombreMeta] = (string) $local->getCode();
            }
        }

        $enMeta = [];

        foreach ($porNombre as $nombre => $idiomas) {
            usort($idiomas, static fn (array $a, array $b): int => strcmp($a['codigo'], $b['codigo']));

            $enMeta[] = [
                'nombre' => $nombre,
                'idiomas' => $idiomas,
                'local' => $dueños[$nombre] ?? null,
                'reclamada' => isset($dueños[$nombre]),
            ];
        }

        // Y el reverso, que es el caso de `menu_tours`: filas locales que dicen apuntar a un
        // nombre que Meta no conoce, o que directamente no tienen nombre. Su estado en el panel
        // no puede venir de ninguna parte más que de la mano de alguien.
        $soloLocales = [];

        foreach ($locales as $local) {
            $nombreMeta = $local->getWhatsappMetaName();
            $tieneCuerpoMeta = ($local->getWhatsappMetaTmpl()['body'] ?? []) !== [];

            if (!$tieneCuerpoMeta) {
                continue; // no pretende ser una plantilla de Meta
            }

            if (is_string($nombreMeta) && $nombreMeta !== '' && isset($porNombre[$nombreMeta])) {
                continue; // está en Meta, ya salió arriba
            }

            $soloLocales[] = [
                'code' => (string) $local->getCode(),
                'nombre' => (string) $local->getName(),
                'nombreMeta' => is_string($nombreMeta) && $nombreMeta !== '' ? $nombreMeta : null,
            ];
        }

        return [
            'enMeta' => $enMeta,
            'soloLocales' => $soloLocales,
            'total' => count($filas),
        ];
    }
}
