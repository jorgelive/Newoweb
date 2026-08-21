<?php

declare(strict_types=1);

namespace App\Tests\Message\Service\Conversacion;

use App\Contract\Frente;
use App\Contract\MomentoDeFrente;
use App\Contract\VinculoComercial;
use App\Cotizacion\Entity\CotizacionConversacionEnlace;
use App\Cotizacion\Entity\CotizacionFile;
use App\Message\Contract\ConversacionEnlaceInterface;
use App\Message\Contract\ProveedorDeEnlacesInterface;
use App\Message\Entity\MessageConversation;
use App\Message\Service\Conversacion\EnlacesDeConversacion;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Un hilo con asuntos de VARIOS negocios.
 *
 * Es el caso que motivó todo: «un cliente de hotel también puede ser cliente de tours». Antes
 * `MessageConversation` tenía una colección tipada al PMS, así que Travel no tenía dónde
 * colgarse sin tocar el núcleo.
 */
final class EnlacesDeConversacionTest extends TestCase
{
    #[Test]
    public function sinProveedoresNoHayAsuntos(): void
    {
        $servicio = new EnlacesDeConversacion([]);

        // Vacío es una respuesta normal: la mayoría de hilos no tienen asuntos de todos los
        // negocios, y el motor de reglas ya sabe programar desde la conversación cuando pasa.
        self::assertSame([], $servicio->de($this->hilo()));
    }

    #[Test]
    public function unClienteDeHotelQueAdemasCompraToursTieneLosDosAsuntos(): void
    {
        $hilo = $this->hilo();
        $servicio = new EnlacesDeConversacion([
            $this->proveedor('hotelero', $this->enlaceFalso('hotelero', 'Tu reserva Casita 3')),
            $this->proveedor('turistico', $this->enlaceFalso('turistico', 'Tu viaje «Nune & Todd»')),
        ]);

        $enlaces = $servicio->de($hilo);

        self::assertCount(2, $enlaces);
        self::assertSame(
            ['Tu reserva Casita 3', 'Tu viaje «Nune & Todd»'],
            array_map(static fn (ConversacionEnlaceInterface $e): string => $e->getEtiqueta(), $enlaces)
        );
    }

    #[Test]
    public function seSabeSiUnHiloTieneAsuntosDeUnNegocio(): void
    {
        $hilo = $this->hilo();
        $servicio = new EnlacesDeConversacion([
            $this->proveedor('hotelero', $this->enlaceFalso('hotelero', 'Tu reserva')),
            $this->proveedor('turistico'),   // registrado pero sin asuntos en este hilo
        ]);

        self::assertTrue($servicio->tieneNegocio($hilo, 'hotelero'));
        self::assertFalse($servicio->tieneNegocio($hilo, 'turistico'), 'Registrado no es lo mismo que tener asuntos.');
        self::assertFalse($servicio->tieneNegocio($hilo, 'inventado'));
    }

    #[Test]
    public function turismoNoOfreceBeds24YAlojamientoNoAcota(): void
    {
        $hilo = $this->hilo();

        $soloTurismo = new EnlacesDeConversacion([
            $this->proveedor('turistico', $this->enlaceFalso('turistico', 'Tu viaje', ['whatsapp_meta', 'email'])),
        ]);
        self::assertSame(['whatsapp_meta', 'email'], $soloTurismo->canalesPosibles($hilo));

        // Alojamiento devuelve `[]` = sin acotar: cuál sirve para cada reserva lo decide el
        // encolador con el `bookId` delante, no este eje.
        $soloHotel = new EnlacesDeConversacion([
            $this->proveedor('hotelero', $this->enlaceFalso('hotelero', 'Tu reserva')),
        ]);
        self::assertSame([], $soloHotel->canalesPosibles($hilo));
    }

    #[Test]
    public function unSoloAsuntoSinAcotarAbreElHiloEntero(): void
    {
        // El hilo fusionado: una estancia de Booking y un viaje. Sin decir a qué asunto va el
        // mensaje, se prefiere ofrecer de más —visible y corregible— a callar un canal
        // legítimo de la reserva, que no lo echa de menos nadie.
        $servicio = new EnlacesDeConversacion([
            $this->proveedor('hotelero', $this->enlaceFalso('hotelero', 'Tu reserva')),
            $this->proveedor('turistico', $this->enlaceFalso('turistico', 'Tu viaje', ['whatsapp_meta'])),
        ]);

        self::assertSame([], $servicio->canalesPosibles($this->hilo()));
    }

    #[Test]
    public function conElAsuntoDelanteSeAcotaSoloAEse(): void
    {
        // Y ésta es la razón de que el eje cuelgue del ASUNTO: en el MISMO hilo, el mensaje al
        // viaje no puede salir por Beds24 y el de la reserva sí.
        $servicio = new EnlacesDeConversacion([
            $this->proveedor('hotelero', $this->enlaceFalso('hotelero', 'Tu reserva', [], 'pms_reserva', 'r-1')),
            $this->proveedor('turistico', $this->enlaceFalso('turistico', 'Tu viaje', ['whatsapp_meta', 'email'], 'cotizacion_file', 'f-1')),
        ]);

        self::assertSame(['whatsapp_meta', 'email'], $servicio->canalesPosibles($this->hilo(), 'cotizacion_file', 'f-1'));
        self::assertSame([], $servicio->canalesPosibles($this->hilo(), 'pms_reserva', 'r-1'));
    }

    #[Test]
    public function unAsuntoQueNoEsDeEsteHiloNoAporta(): void
    {
        // Sin ningún enlace que case, la unión queda vacía = sin acotar. Es el fallo seguro:
        // un asunto que no reconocemos no puede cerrar canales.
        $servicio = new EnlacesDeConversacion([
            $this->proveedor('turistico', $this->enlaceFalso('turistico', 'Tu viaje', ['whatsapp_meta'], 'cotizacion_file', 'f-1')),
        ]);

        self::assertSame([], $servicio->canalesPosibles($this->hilo(), 'cotizacion_file', 'otro'));
    }

    #[Test]
    public function elAsuntoLlevaASuHiloSinPasarPorNingunaIdentidad(): void
    {
        // El camino DURADERO. Quien llega con la dirección de un asunto —el `bookId` de
        // Beds24— necesita saber en qué hilo aterriza, y por identidad no aguanta: un bookId
        // es de la ESTANCIA, no de nadie. Hay 46 repartidos en 38 hilos y una misma persona
        // acumula siete.
        $hilo = $this->hilo();
        $servicio = new EnlacesDeConversacion([$this->proveedor(
            'hotelero',
            $this->enlaceFalso('hotelero', 'Tu reserva', [], 'pms_reserva', 'r-1', conversacion: $hilo)
        )]);

        self::assertSame($hilo, $servicio->hiloTitularDe('pms_reserva', 'r-1'));
        self::assertNull($servicio->hiloTitularDe('pms_reserva', 'otra'), 'Otro asunto no arrastra este hilo.');
        self::assertNull($servicio->hiloTitularDe('cotizacion_file', 'r-1'), 'Otro dominio tampoco.');
    }

    #[Test]
    public function unEnlaceNoTitularNoResuelveElHilo(): void
    {
        // El hilo del acompañante existe y el agente lo lee, pero NO es «el hilo de la
        // reserva»: si lo fuera, un mensaje entrante de Beds24 aterrizaría en el chat del
        // acompañante en vez del titular.
        $servicio = new EnlacesDeConversacion([
            $this->proveedor('hotelero', $this->enlaceFalso('hotelero', 'Tu reserva', [], 'pms_reserva', 'r-1', titular: false, conversacion: $this->hilo())),
        ]);

        self::assertNull($servicio->hiloTitularDe('pms_reserva', 'r-1'));
    }

    #[Test]
    public function promover_un_hilo_a_titular_degrada_al_anterior(): void
    {
        // ⚠️ Es TODO el test. Un asunto con dos titulares programaría su agenda dos veces —la
        // bienvenida, el recordatorio de saldo y el check-out, duplicados—, que es justo lo que
        // el papel vino a evitar. Promover sin degradar es peor que no promover.
        $titular = $this->hilo();
        $acompanante = $this->hilo();

        $viejo = $this->enlaceFalso('hotelero', 'Tu reserva', [], 'pms_reserva', 'r-1', conversacion: $titular);
        $nuevo = $this->enlaceFalso('hotelero', 'Tu reserva', [], 'pms_reserva', 'r-1', titular: false, conversacion: $acompanante);

        $servicio = new EnlacesDeConversacion([$this->proveedor('hotelero', $viejo, $nuevo)]);

        self::assertTrue($servicio->cambiarTitular($acompanante, 'pms_reserva', 'r-1'));
        self::assertTrue($nuevo->esTitular());
        self::assertFalse($viejo->esTitular(), 'El anterior tiene que quedar degradado.');
    }

    #[Test]
    public function no_se_puede_hacer_titular_de_un_asunto_que_no_cuelga_del_hilo(): void
    {
        // No se inventa el enlace: un titular sin enlace sería un asunto atendido desde una
        // conversación que no lo tiene.
        $ajeno = $this->hilo();
        $servicio = new EnlacesDeConversacion([$this->proveedor(
            'hotelero',
            $this->enlaceFalso('hotelero', 'Tu reserva', [], 'pms_reserva', 'r-1', conversacion: $this->hilo())
        )]);

        self::assertFalse($servicio->cambiarTitular($ajeno, 'pms_reserva', 'r-1'));
    }

    // ─────────────────────────────────────────────────────────────────────────

    private function hilo(): MessageConversation
    {
        return new MessageConversation('manual', '+51984123456');
    }

    private function proveedor(string $negocio, ConversacionEnlaceInterface ...$enlaces): ProveedorDeEnlacesInterface
    {
        return new class ($negocio, array_values($enlaces)) implements ProveedorDeEnlacesInterface {
            /** @param list<ConversacionEnlaceInterface> $enlaces */
            public function __construct(private readonly string $negocio, private readonly array $enlaces) {}

            public function getNegocio(): string { return $this->negocio; }

            public function paraConversacion(MessageConversation $conversacion): array { return $this->enlaces; }

            public function titularDeAsunto(string $contextType, string $contextId): ?ConversacionEnlaceInterface
            {
                foreach ($this->enlaces as $enlace) {
                    if ($enlace->esTitular()
                        && $enlace->getContextType() === $contextType
                        && $enlace->getContextId() === $contextId) {
                        return $enlace;
                    }
                }

                return null;
            }

            public function enlaceDeAsunto(
                MessageConversation $conversacion,
                string $contextType,
                string $contextId
            ): ?ConversacionEnlaceInterface {
                foreach ($this->enlaces as $enlace) {
                    if ($enlace->getConversacion() === $conversacion
                        && $enlace->getContextType() === $contextType
                        && $enlace->getContextId() === $contextId) {
                        return $enlace;
                    }
                }

                return null;
            }
        };
    }

    /** @param list<string> $canales */
    private function enlaceFalso(
        string $negocio,
        string $etiqueta,
        array $canales = [],
        string $contextType = 'prueba',
        string $contextId = 'x',
        bool $titular = true,
        ?MessageConversation $conversacion = null,
    ): ConversacionEnlaceInterface {
        return new class ($negocio, $etiqueta, $canales, $titular, $contextType, $contextId, $conversacion) implements ConversacionEnlaceInterface {
            /** @param list<string> $canales */
            public function __construct(
                private readonly string $negocio,
                private readonly string $etiqueta,
                private readonly array $canales,
                private bool $titular,
                private readonly string $contextType,
                private readonly string $contextId,
                private readonly ?MessageConversation $conversacion,
            ) {}

            public function getConversacion(): ?MessageConversation { return $this->conversacion; }
            public function getNegocio(): string { return $this->negocio; }
            public function getContextType(): string { return $this->contextType; }
            public function getContextId(): string { return $this->contextId; }
            public function correoDeContacto(): ?string { return null; }
            public function esTitular(): bool { return $this->titular; }
            public function marcarTitular(bool $esTitular): self { $this->titular = $esTitular; return $this; }
            public function canalesPosibles(): array { return $this->canales; }
            public function getVinculo(): VinculoComercial { return VinculoComercial::Ninguno; }
            public function getMomento(): MomentoDeFrente { return MomentoDeFrente::Venta; }
            public function getMilestones(): array { return []; }
            public function getOrigen(): ?string { return null; }
            public function getAgencia(): ?string { return null; }
            public function procedenciaParaElPrompt(): ?string { return null; }
            public function getCreatedAt(): ?\DateTimeImmutable { return null; }
            public function getEtiqueta(): string { return $this->etiqueta; }
            public function comoFrente(): Frente
            {
                return new Frente($this->negocio, MomentoDeFrente::Venta, $this->etiqueta);
            }
        };
    }
}
