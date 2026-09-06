<?php

declare(strict_types=1);

/**
 * ¿El enfriamiento del escalado evita la segunda tanda de teléfonos?
 *
 * Comprueba `EscalarAlEquipoSkill::yaAvisadoHacePoco()` contra avisos reales simulados: uno
 * reciente de ESTA conversación (debe enfriar), uno de OTRA (no debe), uno viejo (no debe) y uno
 * fallido (no debe, porque nunca sonó). Transacción con rollback.
 *
 *   php tools/pruebas/probar-enfriamiento.php
 */

use App\Agent\Skill\Pms\EscalarAlEquipoSkill;
use App\Kernel;
use App\Message\Entity\Message;
use App\Message\Entity\MessageConversation;
use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__, 2) . '/vendor/autoload.php';
(new Dotenv())->bootEnv(dirname(__DIR__, 2) . '/.env');

$kernel = new Kernel($_SERVER['APP_ENV'] ?? 'dev', (bool) ($_SERVER['APP_DEBUG'] ?? true));
$kernel->boot();
$c = $kernel->getContainer();
$em = $c->get('doctrine')->getManager();

/** Llama al método privado: es una comprobación de lógica, no de API pública. */
$yaAvisado = static function (EscalarAlEquipoSkill $skill, MessageConversation $conv): bool {
    $m = new ReflectionMethod($skill, 'yaAvisadoHacePoco');

    return $m->invoke($skill, $conv);
};

$em->beginTransaction();

try {
    // La skill no es pública en el contenedor. `yaAvisadoHacePoco()` sólo usa el EM, así que se
    // construye sin constructor y se le inyecta esa única dependencia.
    $ref = new ReflectionClass(EscalarAlEquipoSkill::class);
    $skill = $ref->newInstanceWithoutConstructor();
    $propEm = $ref->getProperty('em');
    $propEm->setValue($skill, $em);

    // Explícito y realista: los avisos viven en la conversación de STAFF del operador, y el
    // origen es la conversación del huésped. Cogerlas por «las dos últimas» funcionaba de
    // casualidad y habría dejado de probar el filtro por `contextType` sin avisar.
    $staff = $em->getRepository(MessageConversation::class)->findOneBy(['contextType' => 'staff']);
    $nuestra = $em->getRepository(MessageConversation::class)->findOneBy(['contextType' => 'pms_reserva']);

    if ($staff === null || $nuestra === null) {
        throw new RuntimeException('Hacen falta una conversación staff y una de reserva.');
    }

    $otra = $staff;

    $idOtra = (string) $otra->getId();

    $aviso = static function (string $destinoId, string $origenId, string $estado, ?string $cuando) use ($em): void {
        $m = new Message();
        $m->setConversation($em->getRepository(MessageConversation::class)->find($destinoId));
        $m->setDirection(Message::DIRECTION_OUTGOING);
        $m->setSenderType(Message::SENDER_SYSTEM);
        $m->setStatus($estado);
        $m->setContentExternal('aviso simulado');
        $m->addMetadata('aviso_escalado', true);
        $m->addMetadata('escalado_de', $origenId);
        // ⚠️ En COLUMNA, no sólo en metadata: `yaAvisadoHacePoco()` consulta `m.escaladoDe`
        // desde que el enfriamiento dejó de leer el JSON. Simulando sólo la metadata, el caso
        // «aviso reciente» daba falso negativo y esta prueba pasaba en verde por el motivo
        // equivocado.
        $m->setEscaladoDe($origenId);
        $em->persist($m);
        $em->flush();

        // Estado y fecha se ponen por SQL DESPUÉS del flush, por dos motivos distintos:
        //
        //  - `createdAt` lo escribe el ciclo de vida, así que envejecerlo exige ir por detrás.
        //  - ⚠️ El `status` lo PISA `MessageEnqueuerEntityListener`: al persistir intenta
        //    despachar y en local —sin credenciales de WhatsApp— deja TODOS los mensajes en
        //    `failed`. Como `yaAvisadoHacePoco()` excluye los fallidos a propósito, ningún
        //    aviso simulado llegaba a contar y la prueba daba falso negativo justo en el caso
        //    que de verdad comprueba el enfriamiento.
        $sets = ['status = ?'];
        $args = [$estado];

        if ($cuando !== null) {
            $sets[] = 'created_at = ?';
            $args[] = $cuando;
        }

        $args[] = (string) $m->getId();

        $em->getConnection()->executeStatement(
            'UPDATE msg_message SET ' . implode(', ', $sets) . ' WHERE id = UNHEX(REPLACE(?, "-", ""))',
            $args
        );

        // Sin refresco, la entidad en memoria conserva los valores originales y la consulta de
        // enfriamiento los leería del identity map en vez de la fila ya ajustada.
        $em->refresh($m);
    };

    $idNuestra = (string) $nuestra->getId();
    $fallos = 0;

    $comprobar = static function (string $caso, bool $esperado) use (&$fallos, $skill, $em, $yaAvisado, $idNuestra): void {
        $real = $yaAvisado($skill, $em->getRepository(MessageConversation::class)->find($idNuestra));
        $ok = $real === $esperado;
        printf("%-46s → %-5s (esperado %-5s) %s\n", $caso, $real ? 'sí' : 'no', $esperado ? 'sí' : 'no', $ok ? 'OK' : '✗ FALLA');

        if (!$ok) {
            ++$fallos;
        }
    };

    $comprobar('sin avisos previos', false);

    $aviso($idOtra, $idOtra, Message::STATUS_PENDING, null);
    $comprobar('un aviso reciente de OTRA conversación', false);

    $aviso($idOtra, $idNuestra, Message::STATUS_FAILED, null);
    $comprobar('un aviso nuestro que FALLÓ al encolarse', false);

    $aviso($idOtra, $idNuestra, Message::STATUS_PENDING, (new DateTimeImmutable('-3 hours'))->format('Y-m-d H:i:s'));
    $comprobar('un aviso nuestro de hace 3 horas', false);

    $aviso($idOtra, $idNuestra, Message::STATUS_PENDING, null);
    $comprobar('un aviso nuestro de hace un momento', true);

    // Y la regla que trajo la columna: da igual EN QUÉ HILO acabó el aviso.
    //
    // Antes esto se filtraba por `contextType = 'staff'`, y al fusionar el hilo de alguien del
    // equipo que además es huésped sus avisos dejaban de encontrarse: el enfriamiento fallaba
    // abierto y la guardia volvía a sonar entera. Por eso el caso espera AHORA lo contrario de
    // lo que esperaba entonces.
    $em->getConnection()->executeStatement(
        'DELETE FROM msg_message WHERE conversation_id = UNHEX(REPLACE(?, "-", "")) AND content_external = ?',
        [(string) $staff->getId(), 'aviso simulado']
    );
    $em->clear();
    $aviso($idNuestra, $idNuestra, Message::STATUS_PENDING, null);
    $comprobar('el mismo aviso, en un hilo que ya no es staff', true);

    printf("\n%s\n", $fallos === 0 ? '✅ El enfriamiento distingue lo que debe.' : "❌ $fallos fallo(s).");
} finally {
    $em->rollback();
    echo "(rollback: la base queda como estaba)\n";
}
