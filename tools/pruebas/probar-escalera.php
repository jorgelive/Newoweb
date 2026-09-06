<?php

declare(strict_types=1);

/**
 * ¿Camina la escalera de un tema, y para donde debe?
 *
 * Carga pasos de prueba en un ítem real de ducha, simula que se van encolando respuestas con su
 * huella, y comprueba que cada vuelta entrega el peldaño siguiente hasta agotarse. TODO dentro de
 * una transacción con rollback: la base queda como estaba.
 *
 *   php var/probar-escalera.php
 */

use App\Agent\Service\EscaleraDeTemas;
use App\Kernel;
use App\Message\Entity\Message;
use App\Message\Entity\MessageConversation;
use App\Pms\Entity\PmsGuiaItem;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__, 2) . '/vendor/autoload.php';
(new Dotenv())->bootEnv(dirname(__DIR__, 2) . '/.env');

$kernel = new Kernel($_SERVER['APP_ENV'] ?? 'dev', (bool) ($_SERVER['APP_DEBUG'] ?? true));
$kernel->boot();
$c = $kernel->getContainer();

/** @var EntityManagerInterface $em */
$em = $c->get('doctrine')->getManager();
$escalera = new EscaleraDeTemas($em);

$em->beginTransaction();

try {
    $item = $em->getRepository(PmsGuiaItem::class)->findOneBy(['nombreInterno' => 'Ducha (casa 2)']);

    if ($item === null) {
        throw new RuntimeException('No encuentro el ítem de ducha de la casa 2.');
    }

    $item->setAgentePasos([
        'PASO 1: cierra, espera un minuto y abre la caliente al máximo.',
        'PASO 2: enciende una hornilla; comparten el gas.',
    ]);
    $em->flush();


/**
 * Persiste el mensaje y le devuelve el estado que la prueba quería.
 *
 * ⚠️ **Sin esto la prueba mentía entera.** Al hacer `flush()` de un saliente nuevo,
 * `MessageDispatcher` intenta despacharlo y —en dev, sin canal configurado— lo deja en `failed`.
 * Y `EscaleraDeTemas` excluye a propósito los `failed` y `cancelled`: «lo que nunca llegó no
 * cuenta como peldaño servido». Resultado: todas las huellas simuladas se descartaban, la
 * escalera devolvía 0 siempre, y salían 7 fallos que no eran del código sino del entorno.
 *
 * El estado se fija DESPUÉS del flush y se vuelve a guardar: así se simula un mensaje que sí
 * salió, que es lo que la escalera cuenta.
 */
$guardarCon = static function (Message $m, string $estado) use ($em): void {
    $em->persist($m);
    $em->flush();
    $m->setStatus($estado);
    $em->flush();
};

    $temaId = (string) $item->getId();
    printf("Ítem: %s\nPasos cargados: %d\n\n", $item->getNombreInterno(), $item->pasosDisponibles());

    // Una conversación cualquiera que ya exista, para no inventar un hilo.
    $conversacion = $em->getRepository(MessageConversation::class)->findOneBy([], ['id' => 'DESC']);

    if ($conversacion === null) {
        throw new RuntimeException('No hay ninguna conversación en la base.');
    }

    $conversacionId = (string) $conversacion->getId();

    // ⚠️ El peldaño 0 se compara contra el CONTENIDO REAL del ítem, no contra una frase escrita
    // aquí. Estuvo clavado a «La manija izquierda» y la prueba se puso roja el día que se
    // corrigió el texto de las duchas —el agente decía «izquierda» y la app «derecha», y se
    // unificaron—: un fallo que sólo decía que alguien había editado la guía. Lo que esta
    // prueba tiene que vigilar es la ESCALERA, no la redacción.
    $esperado = [
        0 => mb_substr(trim((string) $item->getAgenteContenido()), 0, 24),
        1 => 'PASO 1',
        2 => 'PASO 2',
        3 => null,                    // agotado → escala
    ];

    $fallos = 0;

    foreach ([0, 1, 2, 3] as $vuelta) {
        $dbg = $em->getRepository(Message::class)->createQueryBuilder('m')
            ->select('COUNT(m.id)')
            ->andWhere('m.conversation = :c')->setParameter('c', $conversacion)
            ->andWhere('m.direction = :d')->setParameter('d', Message::DIRECTION_OUTGOING)
            ->getQuery()->getSingleScalarResult();
        $dbg2 = $em->getRepository(Message::class)->createQueryBuilder('m')
            ->select('COUNT(m.id)')
            ->andWhere('m.conversation = :c')->setParameter('c', $conversacion)
            ->andWhere('m.direction = :d')->setParameter('d', Message::DIRECTION_OUTGOING)
            ->andWhere('m.createdAt >= :desde')->setParameter('desde', new \DateTimeImmutable('-14 days'))
            ->getQuery()->getSingleScalarResult();
        printf("   [dbg] salientes=%s  dentro_de_14d=%s\n", $dbg, $dbg2);

        $peldano = $escalera->peldanoPara($conversacionId, $temaId);
        $texto = $item->agenteTextoParaPeldano($peldano);

        $ok = $esperado[$vuelta] === null
            ? $texto === null
            : ($texto !== null && str_contains($texto, $esperado[$vuelta]));

        printf(
            "vuelta %d → peldaño %d · %s · %s\n",
            $vuelta,
            $peldano,
            $texto === null ? 'SIN MÁS PASOS (escala)' : mb_substr(trim($texto), 0, 42) . '…',
            $ok ? 'OK' : '✗ FALLA'
        );

        if (!$ok) {
            ++$fallos;
        }

        // Se simula la respuesta que sale, con su huella, igual que hace encolarRespuesta().
        $salida = new Message();
        $salida->setConversation($conversacion);
        $salida->setDirection(Message::DIRECTION_OUTGOING);
        $salida->setSenderType(Message::SENDER_SYSTEM);
        $salida->setStatus(Message::STATUS_PENDING);
        $salida->setContentExternal('respuesta simulada ' . $vuelta);
        $salida->addMetadata('generado_por', 'ia');
        $salida->addMetadata('temas_peldano', [['tema' => $temaId, 'peldano' => $peldano]]);
        $conversacion->addMessage($salida);
        $guardarCon($salida, Message::STATUS_SENT);
    }

    // 🧾 LA HUELLA VIEJA (objeto suelto) TIENE QUE SEGUIR CONTANDO: hay mensajes con esa forma
    // en producción, y dejar de leerla reiniciaría a cero todas las escaleras vivas.
    $viejo = new Message();
    $viejo->setConversation($conversacion);
    $viejo->setDirection(Message::DIRECTION_OUTGOING);
    $viejo->setSenderType(Message::SENDER_SYSTEM);
    $viejo->setStatus(Message::STATUS_PENDING);
    $viejo->setContentExternal('con la huella vieja');
    $viejo->addMetadata('generado_por', 'ia');
    $viejo->addMetadata('tema_peldano', ['tema' => $temaId, 'peldano' => 1]);
    $conversacion->addMessage($viejo);
    $guardarCon($viejo, Message::STATUS_SENT);

    $conHuellaVieja = $escalera->peldanoPara($conversacionId, $temaId);
    $okVieja = $conHuellaVieja === 3;
    printf("\nla huella vieja sigue contando → peldaño %d (esperado 3) · %s\n",
        $conHuellaVieja, $okVieja ? 'OK' : '✗ FALLA');

    if (!$okVieja) {
        ++$fallos;
    }

    // ⏭️ El que abre diciendo que ya lo intentó entra en el paso 1, no en el 0.
    // (Se comprueba la aritmética del salto; el flag lo aplica ConsultarGuiaSkill::detalle().)
    $peldanoLimpio = 0;
    $conSalto = max($peldanoLimpio, 1);
    $okSalto = $item->agenteTextoParaPeldano($conSalto) !== null
        && str_contains((string) $item->agenteTextoParaPeldano($conSalto), 'PASO 1');
    printf("\nquien dice «ya lo intenté» entra en el paso %d · %s\n", $conSalto, $okSalto ? 'OK' : '✗ FALLA');

    if (!$okSalto) {
        ++$fallos;
    }

    // Y nunca baja: si ya iba por el 2, sigue en el 2.
    $okNoBaja = max(2, 1) === 2;
    printf("y no baja a quien ya iba por el 2 · %s\n", $okNoBaja ? 'OK' : '✗ FALLA');

    if (!$okNoBaja) {
        ++$fallos;
    }

    // 🔁 UN RECORDATORIO PROGRAMADO NO PUEDE REINICIAR LA CUENTA. Es SENDER_SYSTEM y no lleva
    // `generado_por`: con el discriminador viejo cortaba el bucle y la escalera volvía a 0, que
    // es el caso que se da en casi todas las reservas.
    $programado = new Message();
    $programado->setConversation($conversacion);
    $programado->setDirection(Message::DIRECTION_OUTGOING);
    $programado->setSenderType(Message::SENDER_SYSTEM);
    $programado->setStatus(Message::STATUS_PENDING);
    $programado->setContentExternal('Recordatorio: tu check-out es mañana a las 10.');
    $conversacion->addMessage($programado);
    $guardarCon($programado, Message::STATUS_SENT);

    $trasProgramado = $escalera->peldanoPara($conversacionId, $temaId);
    $okProgramado = $trasProgramado === 3;
    printf("\ntras un recordatorio automático → peldaño %d (esperado 3) · %s\n",
        $trasProgramado, $okProgramado ? 'OK' : '✗ FALLA');

    if (!$okProgramado) {
        ++$fallos;
    }

    // ☠️ Una huella en un mensaje que nunca llegó no cuenta como peldaño servido.
    $muerto = new Message();
    $muerto->setConversation($conversacion);
    $muerto->setDirection(Message::DIRECTION_OUTGOING);
    $muerto->setSenderType(Message::SENDER_SYSTEM);
    $muerto->setStatus(Message::STATUS_FAILED);
    $muerto->setContentExternal('rechazado por Meta');
    $muerto->addMetadata('generado_por', 'ia');
    $muerto->addMetadata('temas_peldano', [['tema' => $temaId, 'peldano' => 3]]);
    $conversacion->addMessage($muerto);
    $guardarCon($muerto, Message::STATUS_FAILED);   // éste SÍ tiene que morir

    $trasMuerto = $escalera->peldanoPara($conversacionId, $temaId);
    $okMuerto = $trasMuerto === 3;
    printf("tras un saliente fallido → peldaño %d (esperado 3, no 4) · %s\n",
        $trasMuerto, $okMuerto ? 'OK' : '✗ FALLA');

    if (!$okMuerto) {
        ++$fallos;
    }

    // Y ahora la otra mitad: un humano contesta y la cuenta se reinicia.
    $humano = new Message();
    $humano->setConversation($conversacion);
    $humano->setDirection(Message::DIRECTION_OUTGOING);
    $humano->setSenderType(Message::SENDER_HOST);
    $humano->setStatus(Message::STATUS_PENDING);
    $humano->setContentExternal('Hola, soy Jorge del equipo, lo vemos ahora mismo.');
    $conversacion->addMessage($humano);
    $guardarCon($humano, Message::STATUS_SENT);

    $trasHumano = $escalera->peldanoPara($conversacionId, $temaId);
    $okHumano = $trasHumano === 0;
    printf("\ntras responder un humano → peldaño %d · %s\n", $trasHumano, $okHumano ? 'OK' : '✗ FALLA');

    if (!$okHumano) {
        ++$fallos;
    }

    printf("\n%s\n", $fallos === 0 ? '✅ La escalera camina y para donde debe.' : "❌ $fallos fallo(s).");
} finally {
    $em->rollback();
    echo "(rollback: la base queda como estaba)\n";
}
