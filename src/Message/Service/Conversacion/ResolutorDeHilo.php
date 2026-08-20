<?php

declare(strict_types=1);

namespace App\Message\Service\Conversacion;

use App\Message\Entity\MessageConversation;
use App\Message\Entity\MessageIdentidad;
use App\Message\Enum\IdentidadTipo;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Encuentra el hilo de alguien por cualquiera de sus identificadores.
 *
 * ## Por qué existe
 *
 * Había **dos resolutores con claves distintas** escribiendo en la misma tabla: WhatsApp
 * buscaba por teléfono y `MessageConversationFactory` por `(contextType, contextId)`. Y el de
 * teléfono emparejaba con `LIKE '%últimos 8 dígitos'` para tolerar prefijos internacionales —
 * lo que resuelve un problema real y **puede casar con otra persona** sin dejar rastro.
 *
 * El resultado está medido en {@see \App\Message\Contract\ConversacionEnlaceInterface}: 20
 * teléfonos con más de un hilo, uno con 247 mensajes repartidos en dos.
 *
 * Aquí la búsqueda es **exacta sobre `(tipo, valor)`**, que es único. Determinista.
 *
 * ## Lo aproximado sugiere, no decide
 *
 * La cola de 8 dígitos sigue existiendo, pero **degradada a candidato**: si sólo casa por ahí,
 * se devuelve y se deja constancia en el log en vez de unir dos historiales en silencio. Y si
 * casa con DOS hilos distintos no se elige ninguno: nace uno nuevo, que es lo reversible.
 *
 * ## `status` NO entra en la clave
 *
 * Buscar sólo entre los abiertos hacía que cerrar un hilo creara otro con el siguiente mensaje.
 * El historial no se perdía, pero dejaba de estar donde se le busca. Ahora se resuelve esté
 * como esté y, si estaba cerrado, **se reabre**: cerrar significa «no hay nada pendiente», no
 * «empieza otro».
 */
final readonly class ResolutorDeHilo
{
    public function __construct(
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * El hilo de este identificador, o null si es la primera vez que aparece.
     *
     * Reabre el hilo si estaba cerrado: quien escribe espera continuar donde lo dejó.
     */
    public function porIdentidad(IdentidadTipo $tipo, string $valor): ?MessageConversation
    {
        $normalizado = $tipo->normalizar($valor);

        if ($normalizado === '') {
            return null;
        }

        $identidad = $this->em->getRepository(MessageIdentidad::class)
            ->findOneBy(['tipo' => $tipo, 'valor' => $normalizado]);

        $conversacion = $identidad?->getConversacion();

        if ($conversacion === null) {
            return null;
        }

        if ($conversacion->getStatus() !== MessageConversation::STATUS_OPEN) {
            $conversacion->setStatus(MessageConversation::STATUS_OPEN);
        }

        return $conversacion;
    }

    /**
     * Candidato por los últimos 8 dígitos, para números que llegan con prefijos distintos.
     *
     * ⚠️ **Devuelve una SOSPECHA, no una certeza**, y por eso queda registrado. Con 8 dígitos
     * hay cien millones de combinaciones y muchos menos clientes, así que acertará casi
     * siempre — pero «casi siempre» sobre el historial de una persona merece quedar escrito.
     *
     * Sólo tiene sentido en teléfonos: dos correos que acaben igual no tienen ninguna relación.
     */
    public function candidatoPorColaDeTelefono(string $telefono): ?MessageConversation
    {
        $normalizado = IdentidadTipo::TELEFONO->normalizar($telefono);

        if (mb_strlen($normalizado) < 9) {
            return null;   // demasiado corto: el falso positivo sería más probable que el acierto
        }

        $cola = mb_substr($normalizado, -8);

        /** @var list<MessageIdentidad> $coincidencias */
        $coincidencias = $this->em->getRepository(MessageIdentidad::class)
            ->createQueryBuilder('i')
            ->where('i.tipo = :tipo')
            ->andWhere('i.valor LIKE :cola')
            ->setParameter('tipo', IdentidadTipo::TELEFONO)
            ->setParameter('cola', '%' . $cola)
            ->setMaxResults(2)
            ->getQuery()
            ->getResult();

        // Dos hilos casan por la cola: elegir uno sería jugárselo. Que decida una persona;
        // devolver null hace que nazca un hilo nuevo, que es lo reversible.
        if (count($coincidencias) !== 1) {
            if ($coincidencias !== []) {
                $this->logger->warning('Hilo ambiguo por cola de teléfono: no se une ninguno.', [
                    'telefono' => $normalizado,
                    'candidatos' => count($coincidencias),
                ]);
            }

            return null;
        }

        $conversacion = $coincidencias[0]->getConversacion();

        $this->logger->info('Hilo resuelto por COLA de teléfono, no por coincidencia exacta.', [
            'telefono' => $normalizado,
            'identidad' => $coincidencias[0]->getValor(),
            'conversacion' => (string) $conversacion?->getId(),
        ]);

        return $conversacion;
    }

    /**
     * Deja registrado el identificador en el hilo.
     *
     * Es lo que hace que la próxima vez la coincidencia sea exacta y no haya que volver a
     * adivinar por la cola: cada mensaje que entra mejora la resolución del siguiente.
     */
    public function vincular(MessageConversation $conversacion, IdentidadTipo $tipo, string $valor, ?string $origen = null): void
    {
        if ($tipo->normalizar($valor) === '') {
            return;
        }

        $conversacion->addIdentidad(new MessageIdentidad($tipo, $valor, $origen));
    }
}
