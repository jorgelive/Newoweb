<?php

declare(strict_types=1);

namespace App\Tests\Message\Service\Conversacion;

use App\Contract\Frente;
use App\Contract\MomentoDeFrente;
use App\Contract\VinculoComercial;
use App\Message\Contract\ChannelEnqueuerInterface;
use App\Message\Contract\ConversacionEnlaceInterface;
use App\Message\Contract\MessageQueueItemInterface;
use App\Message\Contract\ProveedorDeEnlacesInterface;
use App\Message\Entity\Message;
use App\Message\Entity\MessageChannel;
use App\Message\Entity\MessageConversation;
use App\Message\Service\Conversacion\CanalesDisponibles;
use App\Message\Service\Conversacion\EnlacesDeConversacion;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\UnitOfWork;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Qué canales se le ofrecen al operador, y por qué no los demás.
 *
 * La regla vivía copiada en `ChatView.vue` —`contextType !== 'pms_reserva'` y una lista de
 * orígenes calcada de `Beds24SendEnqueuer`—, y la copia juzgaba por la CABECERA del hilo: la
 * señal que dejó de ser fiable al fusionar las conversaciones por persona.
 *
 * Aquí se comprueba que las dos capas se juntan bien, que es lo único que hace este servicio:
 * lo estructural (¿existe el canal para este asunto?) y lo de registro (¿está usable ahora?).
 */
final class CanalesDisponiblesTest extends TestCase
{
    #[Test]
    public function un_viaje_no_ofrece_beds24_aunque_el_encolador_lo_daria_por_bueno(): void
    {
        // Es la separación que importa: el encolador diría que sí —no sabe de dominios— y lo
        // que lo descarta es que el ASUNTO no se alcanza por ahí. Un expediente de viaje no
        // tiene `bookId` ni lo va a tener.
        $canales = $this->servicio(
            posibles: ['whatsapp_meta'],
            encoladores: ['beds24' => true, 'whatsapp_meta' => true],
        )->para($this->hilo());

        self::assertSame(
            ['beds24' => 'no_existe_para_el_asunto', 'whatsapp_meta' => null],
            $this->motivos($canales)
        );
    }

    #[Test]
    public function sin_acotar_manda_lo_que_diga_el_encolador(): void
    {
        // Lista vacía = sin acotar (alojamiento). Entonces decide la capa de registro: una
        // reserva directa no tiene hilo en Beds24 por mucho que el canal exista para el negocio.
        $canales = $this->servicio(
            posibles: [],
            encoladores: ['beds24' => false, 'whatsapp_meta' => true],
        )->para($this->hilo());

        self::assertSame(
            ['beds24' => 'sin_datos_o_vetado', 'whatsapp_meta' => null],
            $this->motivos($canales)
        );
    }

    #[Test]
    public function un_canal_activo_sin_encolador_se_ofrece_apagado_no_ausente(): void
    {
        // `email` lleva meses así. Que desaparezca de la barra es peor que verlo en gris: el
        // operador no puede distinguir «no existe» de «todavía no está montado».
        $canales = $this->servicio(posibles: [], encoladores: [])->para($this->hilo());

        self::assertSame(
            ['beds24' => 'sin_datos_o_vetado', 'whatsapp_meta' => 'sin_datos_o_vetado'],
            $this->motivos($canales)
        );
    }

    // ─────────────────────────────────────────────────────────────────────────

    private function hilo(): MessageConversation
    {
        return new MessageConversation('pms_reserva', 'r-1');
    }

    /**
     * @param list<array{id: string, nombre: string, disponible: bool, motivo: ?string}> $canales
     * @return array<string, ?string>
     */
    private function motivos(array $canales): array
    {
        $salida = [];

        foreach ($canales as $canal) {
            $salida[$canal['id']] = $canal['motivo'];
            self::assertSame($canal['motivo'] === null, $canal['disponible'], 'disponible y motivo tienen que concordar.');
        }

        return $salida;
    }

    /**
     * @param list<string>        $posibles    lo que declara el asunto
     * @param array<string, bool> $encoladores id de canal => qué contesta su encolador
     */
    private function servicio(array $posibles, array $encoladores): CanalesDisponibles
    {
        $repo = $this->createStub(EntityRepository::class);
        $repo->method('findBy')->willReturn([
            new MessageChannel()->setId('beds24')->setName('Beds24'),
            new MessageChannel()->setId('whatsapp_meta')->setName('Whatapp Meta'),
        ]);

        $em = $this->createStub(EntityManagerInterface::class);
        // `ResolutorDeHilo` mira lo pendiente de insertar antes que la base: sin este doble,
        // `getUnitOfWork()` devuelve null y revienta. Vacío = «nada a medio guardar».
        $uow = $this->createStub(UnitOfWork::class);
        $uow->method('getScheduledEntityInsertions')->willReturn([]);
        $em->method('getUnitOfWork')->willReturn($uow);
        $em->method('getRepository')->willReturn($repo);

        $lista = [];
        foreach ($encoladores as $id => $contesta) {
            $lista[] = $this->encolador((string) $id, $contesta);
        }

        return new CanalesDisponibles($em, $this->enlaces($posibles), $lista);
    }

    /** @param list<string> $posibles */
    private function enlaces(array $posibles): EnlacesDeConversacion
    {
        $enlace = new class ($posibles) implements ConversacionEnlaceInterface {
            /** @param list<string> $posibles */
            public function __construct(private readonly array $posibles) {}

            public function getConversacion(): ?MessageConversation { return null; }
            public function getNegocio(): string { return 'prueba'; }
            public function getContextType(): string { return 'pms_reserva'; }
            public function getContextId(): string { return 'r-1'; }
            public function getVinculo(): VinculoComercial { return VinculoComercial::Ninguno; }
            public function getMomento(): MomentoDeFrente { return MomentoDeFrente::Venta; }
            public function getMilestones(): array { return []; }
            public function getOrigen(): ?string { return null; }
            public function getAgencia(): ?string { return null; }
            public function procedenciaParaElPrompt(): ?string { return null; }
            public function getCreatedAt(): ?DateTimeImmutable { return null; }
            public function getEtiqueta(): string { return 'Asunto de prueba'; }
            public function correoDeContacto(): ?string { return null; }
            public function correoEsExclusivo(): bool { return false; }
            public function esTitular(): bool { return true; }
            public function marcarTitular(bool $esTitular): self { return $this; }
            public function canalesPosibles(): array { return $this->posibles; }
            public function comoFrente(): Frente { return new Frente('prueba', MomentoDeFrente::Venta, 'Asunto de prueba'); }
        };

        $proveedor = new class ($enlace) implements ProveedorDeEnlacesInterface {
            public function __construct(private readonly ConversacionEnlaceInterface $enlace) {}

            public function getNegocio(): string { return 'prueba'; }
            public function paraConversacion(MessageConversation $conversacion): array { return [$this->enlace]; }
            public function titularDeAsunto(string $contextType, string $contextId): ?ConversacionEnlaceInterface { return null; }

            public function enlaceDeAsunto(
                MessageConversation $conversacion,
                string $contextType,
                string $contextId
            ): ?ConversacionEnlaceInterface { return null; }
        };

        return new EnlacesDeConversacion([$proveedor]);
    }

    private function encolador(string $canalId, bool $contesta): ChannelEnqueuerInterface
    {
        return new class ($canalId, $contesta) implements ChannelEnqueuerInterface {
            public function __construct(private readonly string $canalId, private readonly bool $contesta) {}

            public function supports(MessageChannel $channel): bool { return $channel->getId() === $this->canalId; }
            public function isValid(Message $message): bool { return $this->contesta; }
            public function isAlreadyEnqueued(Message $message): bool { return false; }
            public function createQueueEntity(Message $m, MessageChannel $c, DateTimeImmutable $r): ?MessageQueueItemInterface { return null; }
            public function disponiblePara(MessageConversation $c, ?string $t = null, ?string $i = null): bool { return $this->contesta; }
        };
    }
}
