<?php

declare(strict_types=1);

namespace App\Tests\Pms\Guia;

use App\Pms\Entity\PmsEstablecimiento;
use App\Pms\Entity\PmsEventoCalendario;
use App\Pms\Entity\PmsEventoEstado;
use App\Pms\Entity\PmsGuiaItem;
use App\Pms\Entity\PmsReserva;
use App\Pms\Entity\PmsUnidad;
use App\Pms\Enum\PmsGuiaVisibilidad;
use App\Pms\Guia\PmsGuiaAcceso;
use App\Pms\Guia\PmsGuiaAccesoEstado;
use App\Pms\Guia\PmsGuiaContexto;
use App\Pms\Guia\PmsGuiaEnlaceDelEquipo;
use App\Pms\Service\Message\PmsMessageDataResolver;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * El enlace del equipo a una ficha `PorEnlace` (`docs/PmsGuiaHuesped.md` §7 sexies).
 *
 * Lo que se protege: que sólo abra lo que firmó este servidor, que caduque, que muera si la ficha
 * deja de ser `PorEnlace` o la reserva se cancela, y que esas fichas no se vean por ningún otro
 * camino. Unitario puro: entidades reales y un EntityManager de doble.
 */
final class PmsGuiaEnlaceDelEquipoTest extends TestCase
{
    private PmsReserva $reserva;
    private PmsGuiaItem $item;
    private PmsGuiaEnlaceDelEquipo $enlaces;

    protected function setUp(): void
    {
        $this->reserva = new PmsReserva();
        $this->reserva->initializeId();
        $this->reserva->addEventosCalendario($this->estancia('+1 day 14:00', '+3 days 10:00'));

        $this->item = (new PmsGuiaItem())
            ->setCodigo('llave-emergencia')
            ->setVisibilidad(PmsGuiaVisibilidad::PorEnlace);

        $this->enlaces = $this->servicio('secreto-de-prueba');
    }

    #[Test]
    public function lo_que_firma_lo_abre(): void
    {
        $token = $this->enlaces->emitir($this->reserva, $this->item);
        self::assertNotNull($token);

        $abierto = $this->enlaces->leer($token);

        self::assertNotNull($abierto);
        self::assertSame($this->item, $abierto->item);
        self::assertSame($this->reserva, $abierto->reserva);
    }

    #[Test]
    public function un_token_tocado_o_firmado_con_otro_secreto_no_abre_nada(): void
    {
        $token = (string) $this->enlaces->emitir($this->reserva, $this->item);
        [$carga, $firma] = explode('.', $token);

        // Otra reserva, otro ítem o más vida: cualquier cambio en la carga rompe la firma.
        $otraCarga = rtrim(strtr(base64_encode(str_replace('llave-emergencia', 'pago-efectivo', (string) base64_decode(strtr($carga, '-_', '+/')))), '+/', '-_'), '=');

        self::assertNull($this->enlaces->leer($otraCarga . '.' . $firma));
        self::assertNull($this->enlaces->leer($carga . '.' . strrev($firma)));
        self::assertNull($this->servicio('otro-secreto')->leer($token));
        self::assertNull($this->enlaces->leer('basura'));
        self::assertNull($this->enlaces->leer('a.b.c'));
    }

    #[Test]
    public function caduca_al_terminar_la_estancia_pero_nunca_antes_de_48_horas(): void
    {
        $token = (string) $this->enlaces->emitir($this->reserva, $this->item);

        self::assertNotNull($this->enlaces->leer($token, new DateTimeImmutable('+3 days 20:00')));
        self::assertNull($this->enlaces->leer($token, new DateTimeImmutable('+5 days')));

        // Estancia que acaba mañana: el enlace vale igual 48 h desde que se manda.
        $corta = new PmsReserva();
        $corta->initializeId();
        $corta->addEventosCalendario($this->estancia('-1 day 14:00', 'today 10:00'));
        $this->reserva = $corta;

        $tokenCorto = (string) $this->enlaces->emitir($corta, $this->item);
        $abierto = $this->enlaces->leer($tokenCorto);

        self::assertNotNull($abierto);
        self::assertGreaterThanOrEqual((new DateTimeImmutable('+47 hours'))->getTimestamp(), $abierto->expiraEn->getTimestamp());
    }

    #[Test]
    public function muere_si_la_ficha_deja_de_ser_por_enlace(): void
    {
        $token = (string) $this->enlaces->emitir($this->reserva, $this->item);

        // Alguien la publica en la guía: lo firmado era «contenido reservado», no «este código».
        $this->item->setVisibilidad(PmsGuiaVisibilidad::SoloVentana);

        self::assertNull($this->enlaces->leer($token));
    }

    #[Test]
    public function muere_si_la_reserva_se_cancela(): void
    {
        $token = (string) $this->enlaces->emitir($this->reserva, $this->item);

        foreach ($this->reserva->getEventosCalendario() as $evento) {
            $evento->setEstado(new PmsEventoEstado(PmsEventoEstado::CODIGO_CANCELADA));
        }

        self::assertNull($this->enlaces->leer($token));
    }

    #[Test]
    public function no_emite_para_una_ficha_de_la_guia_normal(): void
    {
        $normal = (new PmsGuiaItem())->setCodigo('llaves')->setVisibilidad(PmsGuiaVisibilidad::SoloVentana);

        self::assertNull($this->enlaces->emitir($this->reserva, $normal));
    }

    #[Test]
    public function la_guia_no_abre_una_ficha_por_enlace_ni_la_anuncia_con_candado(): void
    {
        $enVentana = new PmsGuiaAcceso(PmsGuiaAccesoEstado::Activa);

        self::assertFalse($enVentana->permite(PmsGuiaVisibilidad::PorEnlace));
        self::assertFalse($enVentana->debeAnunciarBloqueo(PmsGuiaVisibilidad::PorEnlace));
        self::assertFalse(PmsGuiaAcceso::publico()->permite(PmsGuiaVisibilidad::PorEnlace));

        // El enlace añade la ficha y nada más: lo demás sigue el estado de la estancia.
        $sinPagar = PmsGuiaAcceso::porEnlace(new PmsGuiaAcceso(PmsGuiaAccesoEstado::SinPago));
        self::assertTrue($sinPagar->permite(PmsGuiaVisibilidad::PorEnlace));
        self::assertFalse($sinPagar->estaAbierto(), 'El enlace no abre el código de las llaves antes de tiempo.');
        self::assertFalse($sinPagar->permite(PmsGuiaVisibilidad::SoloVentana));
    }

    #[Test]
    public function el_enlace_no_llega_a_quien_lee_la_reserva_sin_mandar_una_plantilla(): void
    {
        $datos = [
            'guest_name' => 'Ana',
            'codigo_caja_dinero' => '2013E',
            PmsGuiaEnlaceDelEquipo::variable('llave-emergencia') => 'https://pax/acceso/x',
        ];

        self::assertSame(['guest_name' => 'Ana'], PmsMessageDataResolver::sinClavesDeAcceso($datos));
        self::assertSame('acceso_llave_emergencia', PmsGuiaEnlaceDelEquipo::variable('llave-emergencia'));
    }

    private function estancia(string $inicio, string $fin): PmsEventoCalendario
    {
        return (new PmsEventoCalendario())
            ->setPmsUnidad(new PmsUnidad())
            ->setInicio(new DateTimeImmutable($inicio))
            ->setFin(new DateTimeImmutable($fin))
            ->setEstado(new PmsEventoEstado(PmsEventoEstado::CODIGO_CONFIRMADA));
    }

    private function servicio(string $secreto): PmsGuiaEnlaceDelEquipo
    {
        $items = $this->createStub(EntityRepository::class);
        $items->method('findOneBy')->willReturnCallback(
            fn (array $criterio): ?PmsGuiaItem => ($criterio['codigo'] ?? null) === $this->item->getCodigo() ? $this->item : null
        );
        $items->method('findBy')->willReturnCallback(fn (): array => [$this->item]);

        $reservas = $this->createStub(EntityRepository::class);
        $reservas->method('find')->willReturnCallback(
            fn (mixed $id): ?PmsReserva => (string) $id === (string) $this->reserva->getId() ? $this->reserva : null
        );

        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getRepository')->willReturnCallback(
            static fn (string $clase): EntityRepository => $clase === PmsGuiaItem::class ? $items : $reservas
        );

        return new PmsGuiaEnlaceDelEquipo($em, $secreto, 'https://pax.example');
    }

    #[Test]
    public function el_codigo_de_la_caja_del_dinero_solo_entra_en_el_contexto_del_enlace(): void
    {
        $establecimiento = (new PmsEstablecimiento())->setCodigoCajaDinero('2013E');
        $unidad = (new PmsUnidad())->setEstablecimiento($establecimiento);
        $evento = $this->estancia('+1 day 14:00', '+3 days 10:00')->setPmsUnidad($unidad);

        $guia = PmsGuiaContexto::construir($unidad, $evento);
        self::assertArrayNotHasKey('codigo_caja_dinero', $guia->valores);
        self::assertArrayNotHasKey('codigo_caja_dinero', $guia->sensibles, 'Con la ventana abierta, cualquier ficha lo enseñaría.');

        $deEnlace = PmsGuiaContexto::construir($unidad, $evento, conMediosDelEquipo: true);
        self::assertSame('2013E', $deEnlace->valores['codigo_caja_dinero'] ?? null);
    }

    #[Test]
    public function una_reserva_terminada_hace_tiempo_no_da_enlace_y_ninguno_dura_mas_de_dos_semanas(): void
    {
        $vieja = new PmsReserva();
        $vieja->initializeId();
        $vieja->addEventosCalendario($this->estancia('-30 days 14:00', '-20 days 10:00'));
        self::assertNull($this->enlaces->emitir($vieja, $this->item));

        $larga = new PmsReserva();
        $larga->initializeId();
        $larga->addEventosCalendario($this->estancia('+60 days 14:00', '+70 days 10:00'));
        $this->reserva = $larga;

        $abierto = $this->enlaces->leer((string) $this->enlaces->emitir($larga, $this->item));
        self::assertNotNull($abierto);
        self::assertLessThanOrEqual(
            (new DateTimeImmutable(sprintf('+%d days +1 hour', PmsGuiaEnlaceDelEquipo::DIAS_MAXIMOS)))->getTimestamp(),
            $abierto->expiraEn->getTimestamp(),
        );
    }

    #[Test]
    public function un_enlace_propio_caducado_sigue_siendo_de_firma_valida(): void
    {
        $token = (string) $this->enlaces->emitir($this->reserva, $this->item);

        self::assertTrue($this->enlaces->firmaValida($token));
        self::assertNull($this->enlaces->leer($token, new DateTimeImmutable('+30 days')));
        self::assertFalse($this->servicio('otro-secreto')->firmaValida($token));
    }
}
