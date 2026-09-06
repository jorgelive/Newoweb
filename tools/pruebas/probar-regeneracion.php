<?php

declare(strict_types=1);

/**
 * LA PRUEBA DEFINITIVA del motor por asunto: borrar el calendario pendiente, regenerarlo desde
 * cero y comprobar que sale EXACTAMENTE el mismo.
 *
 * El `--dry-run` de `app:message:sync-rules` demuestra media verdad: que sobre lo que ya existe
 * el motor no duplica ni regenera de más. La otra media —**que partiendo de cero produce el
 * mismo calendario**— sólo se ve borrando y volviendo a generar, y es la que de verdad importa:
 * si el motor por asunto programara una fecha distinta, o se dejara un mensaje, se vería aquí y
 * en ningún otro sitio.
 *
 * ── Por qué no hace falta borrar de verdad ──────────────────────────────────
 * Todo ocurre dentro de UNA transacción que se deshace al final, en un solo proceso. El borrado
 * es real, la regeneración es real —el mismo `MessageRuleEngine` que corre en producción, con
 * `TRIGGER_COMMAND` y `force`, no un doble— y la comparación es sobre filas de verdad. Lo único
 * que no ocurre es el `COMMIT`.
 *
 * Es la misma técnica que `var/probar-limpieza.php`, y sirve por lo mismo: lo que este flujo
 * produce son FILAS que un worker leerá después. Si nunca se confirman, nadie las lee.
 *
 * ── Qué se borra ────────────────────────────────────────────────────────────
 * SÓLO los mensajes generados por reglas (`rule_id IS NOT NULL`) que no llegaron a salir:
 * `queued`, `failed` y **`cancelled`**. Ni los mensajes reales de la conversación, ni los ya
 * enviados: el motor no reprograma lo que ya salió, así que incluirlos mediría la lógica de
 * caducidad en vez de la de generación.
 *
 * ⚠️ **Los `cancelled` se incluyen desde el 06/09/2026, y sin eso la prueba MENTÍA.** Se
 * dejaban fuera, y `MessageRuleEngine` se niega a regenerar sobre un mensaje cancelado —«sólo
 * generamos un intento nuevo si la cancelación fue una decisión de negocio definitiva»—: así
 * que todo `failed` con un gemelo `cancelled` de la misma regla se contaba como «no volvió» y
 * la prueba salía en ❌ sin que hubiera nada roto. Pasó con 12 mensajes de 4 conversaciones,
 * tres de ellas con TODAS las estancias canceladas, donde no regenerar era lo correcto.
 *
 * Uso: php var/probar-regeneracion.php
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use App\Message\Entity\MessageConversation;
use App\Message\Service\Queue\MessageRuleEngine;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\LazyCommand;

(new Symfony\Component\Dotenv\Dotenv())->bootEnv(dirname(__DIR__, 2) . '/.env');
$_SERVER['APP_ENV'] = 'dev';
$kernel = new App\Kernel('dev', true);
$kernel->boot();

// `--sin-force` aísla los rescates: `force` repara y rescata a propósito, así que sin él no
// debería aparecer ni un mensaje que no estuviera antes. Es lo que distingue «el motor repara»
// de «el motor se inventa mensajes».
$conForce = !in_array('--sin-force', $argv, true);

/** @var EntityManagerInterface $em */
$em = $kernel->getContainer()->get('doctrine')->getManager();
$conexion = $em->getConnection();

// El motor es un servicio privado: se saca del comando que ya lo recibe, mismo truco que usan
// los otros scripts de comprobación de este directorio.
$app = new Application($kernel);
$comando = $app->find('app:message:sync-rules');
if ($comando instanceof LazyCommand) {
    $comando = $comando->getCommand();
}

$motor = null;
foreach ((new ReflectionClass($comando))->getProperties() as $prop) {
    $valor = $prop->getValue($comando);
    if ($valor instanceof MessageRuleEngine) {
        $motor = $valor;
        break;
    }
}

if ($motor === null) {
    exit("No se pudo obtener el MessageRuleEngine del comando.\n");
}

/** @return array<string, string> clave del mensaje => estado */
$foto = static function (Connection $conexion): array {
    $filas = $conexion->fetchAllAssociative(
        "SELECT BIN_TO_UUID(conversation_id) conv, BIN_TO_UUID(rule_id) regla, scheduled_at, status,
                (scheduled_at >= NOW()) futuro
           FROM msg_message
          WHERE rule_id IS NOT NULL AND status IN ('queued','failed','cancelled')
            AND conversation_id IN (SELECT id FROM msg_conversation WHERE status = 'open')"
    );

    $foto = [];

    foreach ($filas as $f) {
        // La clave es lo que define el mensaje programado: para quién, por qué regla y cuándo.
        // El id no entra: al regenerar es otro, y comparar ids sólo demostraría que se borraron.
        $foto[sprintf('%s|%s|%s', $f['conv'], $f['regla'], substr((string) $f['scheduled_at'], 0, 16))] = (bool) $f['futuro'];
    }

    return $foto;
};

$antes = $foto($conexion);
printf("\n=== ANTES (%s) ===\n  %d mensajes pendientes generados por reglas\n", $conForce ? 'con --force' : 'SIN force', count($antes));

$conexion->beginTransaction();

try {
    // ⚠️ **Sólo de las conversaciones que se van a REGENERAR**, y esto era el fallo de fondo de
    // la prueba: borraba los mensajes de las 366 conversaciones y regeneraba únicamente las 35
    // `open`, así que todo lo de las 331 `closed`/`archived` desaparecía por construcción y se
    // contaba como «perdido». Se acusaba al motor de perder mensajes que la propia prueba había
    // borrado sin intención de reponerlos.
    $borrados = $conexion->executeStatement(
        "DELETE m FROM msg_message m
           JOIN msg_conversation c ON c.id = m.conversation_id
          WHERE m.rule_id IS NOT NULL
            AND m.status IN ('queued','failed','cancelled')
            AND c.status = 'open'"
    );
    printf("\n=== BORRADO (dentro de la transacción) ===\n  %d mensajes eliminados\n", $borrados);

    $em->clear();

    $ids = $conexion->fetchFirstColumn(
        "SELECT BIN_TO_UUID(id) FROM msg_conversation WHERE status = 'open'"
    );

    printf("\n=== REGENERANDO sobre %d conversaciones abiertas ===\n", count($ids));

    $fallos = [];

    foreach ($ids as $id) {
        try {
            $conversacion = $em->getRepository(MessageConversation::class)->find($id);

            if ($conversacion !== null) {
                $motor->syncConversationRules($conversacion, MessageRuleEngine::TRIGGER_COMMAND, $conForce);
            }
        } catch (Throwable $e) {
            $fallos[] = sprintf('%s: %s', $id, $e->getMessage());
        }
    }

    $em->flush();

    $despues = $foto($conexion);

    $faltan = array_diff_key($antes, $despues);
    $sobran = array_diff_key($despues, $antes);

    // ── El criterio, y por qué no es «que salga lo mismo» a secas ────────────
    // Un mensaje cuya fecha YA PASÓ no se regenera, y eso es correcto: el motor no resucita lo
    // vencido (PAST_THRESHOLD). Exigir que vuelva sería exigir un bug. Lo que sí es innegociable
    // es que **todo lo que aún no ha vencido vuelva idéntico**: misma conversación, misma regla,
    // misma fecha y hora. Ahí es donde se vería que el motor por asunto programa distinto.
    $faltanFuturos = array_filter($faltan, static fn (bool $futuro): bool => $futuro);
    $faltanVencidos = count($faltan) - count($faltanFuturos);

    printf("\n=== DESPUÉS ===\n  %d mensajes regenerados\n", count($despues));
    // ⚠️ Y una conversación cuyo ASUNTO está cancelado tampoco pierde nada al no regenerar: sus
    // mensajes vivos son restos de antes de la cancelación, y que el motor no los reponga es
    // exactamente lo que tiene que hacer. Sin este filtro la prueba acusaba al motor de perder
    // mensajes de estancias que ya no existen — tres de los cuatro casos que quedaban.
    $conversacionesMuertas = [];

    foreach ($conexion->fetchAllAssociative(
        "SELECT BIN_TO_UUID(co.id) conv
           FROM msg_conversation co
           JOIN pms_reserva r ON r.id = UUID_TO_BIN(co.context_id)
          WHERE co.context_type = 'pms_reserva'
            AND NOT EXISTS (SELECT 1 FROM pms_evento_calendario e
                             WHERE e.reserva_id = r.id AND e.estado_id <> 'cancelada')"
    ) as $f) {
        $conversacionesMuertas[(string) $f['conv']] = true;
    }

    // ⚠️ Un futuro que «no vuelve» NO siempre es una pérdida: si la estancia cambió de fechas,
    // el mensaje viejo estaba anclado a la fecha vieja y el motor produce el mismo mensaje en la
    // NUEVA hora — la clave `conversación|regla|hora` cambia y la comparación literal lo lee como
    // desaparecido. Sólo es una pérdida de verdad si esa pareja conversación+regla se queda **sin
    // ningún** mensaje vivo después.
    $reprogramados = [];
    $deAsuntoCancelado = [];
    $perdidos = [];

    foreach ($faltanFuturos as $clave => $_) {
        [$conv, $regla] = explode('|', (string) $clave) + [1 => ''];
        $sigueViva = false;

        foreach (array_keys($despues) as $otra) {
            if (str_starts_with((string) $otra, $conv . '|' . $regla . '|')) {
                $sigueViva = true;
                break;
            }
        }

        if ($sigueViva) {
            $reprogramados[$clave] = true;
        } elseif (isset($conversacionesMuertas[$conv])) {
            $deAsuntoCancelado[$clave] = true;
        } else {
            $perdidos[$clave] = true;
        }
    }

    printf("\n=== COMPARACIÓN ===\n");
    printf("  futuros PERDIDOS         : %d   ← tiene que ser 0\n", count($perdidos));
    printf("  futuros reprogramados    : %d   (misma regla, otra hora: la estancia cambió)\n", count($reprogramados));
    printf("  de asuntos CANCELADOS    : %d   (correcto: no se repone lo de una estancia muerta)\n", count($deAsuntoCancelado));
    printf("  vencidos no regenerados  : %d   (correcto: no se resucita lo caducado)\n", $faltanVencidos);
    printf("  aparecidos de nuevo      : %d   (rescates y hitos que --force repara)\n", count($sobran));

    foreach (array_slice(array_keys($perdidos), 0, 8) as $k) {
        printf("    ✗ PERDIDO  %s\n", $k);
    }

    foreach (array_slice(array_keys($reprogramados), 0, 5) as $k) {
        printf("    ↻ reprogramado  %s\n", $k);
    }

    foreach (array_slice(array_keys($sobran), 0, 5) as $k) {
        printf("    + nuevo  %s\n", $k);
    }

    if ($fallos !== []) {
        printf("\n  ⚠️ %d conversaciones fallaron:\n", count($fallos));
        foreach (array_slice($fallos, 0, 5) as $f) {
            printf("    %s\n", $f);
        }
    }

    printf(
        "\n%s\n",
        ($perdidos === [] && $fallos === [])
            ? '✅ El motor regenera desde cero TODO el calendario vivo, mensaje a mensaje, misma fecha.'
            : '❌ HAY DIFERENCIAS EN MENSAJES VIVOS: revisar antes de desplegar.'
    );
} finally {
    $conexion->rollBack();
    printf("\n(transacción deshecha: la base queda exactamente como estaba)\n");
}
