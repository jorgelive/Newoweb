<?php

declare(strict_types=1);

namespace App\Pms\Service\Message;

use App\Message\Contract\MessageDataResolverInterface;
use App\Pms\Entity\PmsChannel;
use App\Pms\Entity\PmsEventoBeds24Link;
use App\Pms\Entity\PmsEventoCalendario;
use App\Pms\Entity\PmsInformacionFinanciera;
use App\Pms\Finanzas\PmsRedactorDeCobro;
use App\Pms\Entity\PmsReserva;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AutoconfigureTag('app.message_data_resolver')]
class PmsMessageDataResolver implements MessageDataResolverInterface
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly TelefonoDeContacto $telefonos,
        private readonly PmsRedactorDeCobro $redactor,
        private readonly PmsRedactorDeEstancias $estancias,
        #[Autowire('%pax_book_guide_url%')]
        private readonly string $paxBookGuideUrl,
        #[Autowire('%pax_book_guide_url_nd%')]
        private readonly string $paxBookGuideUrlNd,
        #[Autowire('%pax_catalog_url%')]
        private readonly string $paxCatalogUrl,
        #[Autowire('%pax_catalog_url_nd%')]
        private readonly string $paxCatalogUrlNd
    ) {}

    public function supports(string $contextType): bool
    {
        return $contextType === 'pms_reserva';
    }

    /**
     * Un importe listo para leerse, con su moneda dentro y sumando todas las que haya.
     *
     * `US$ 65.97` con una sola; `US$ 65.97 + S/ 50.00` con dos. Nunca un número pelado: en una
     * plantilla, un importe sin moneda es una cifra que el huésped no puede comprobar.
     */
    private function importe(?PmsInformacionFinanciera $info, string $campo): string
    {
        if ($info === null) {
            return '0.00';
        }

        $partes = [];

        foreach ($info->getTotalesPorMoneda() as $fila) {
            $partes[] = trim(($fila['simbolo'] ?? $fila['moneda']) . ' ' . $fila[$campo]);
        }

        return $partes === [] ? '0.00' : implode(' + ', $partes);
    }

    /**
     * El código de moneda, **sólo si hay una**.
     *
     * Con dos, vacío: una plantilla que escriba «{balance} {currency}» produciría
     * «US$ 65.97 + S/ 50.00 USD». Preferible una cadena de menos que una mentira.
     */
    private function monedaUnica(?PmsInformacionFinanciera $info): string
    {
        $totales = $info?->getTotalesPorMoneda() ?? [];

        return count($totales) === 1
            ? (string) $totales[0]['moneda']
            : (count($totales) === 0 ? (string) ($info?->getMoneda()?->getId() ?? '') : '');
    }

    private function getReserva(string $contextId): ?PmsReserva
    {
        return $this->entityManager->getRepository(PmsReserva::class)->find($contextId);
    }

    public function getContextName(string $contextId): ?string
    {
        $reserva = $this->getReserva($contextId);
        return $reserva ? trim($reserva->getNombreCliente() . ' ' . $reserva->getApellidoCliente()) : null;
    }

    public function getPhoneNumber(string $contextId): ?string
    {
        $reserva = $this->getReserva($contextId);
        return $this->telefonos->para($reserva);
    }

    public function getMetadata(string $contextId): array
    {
        $reserva = $this->getReserva($contextId);
        if (!$reserva) {
            return [];
        }

        // 1. Intentamos obtener el ID Principal
        $targetBookId = $reserva->getBeds24MasterId();

        // 2. Sino lo buscamos en el link
        if (empty($targetBookId)) {
            /** @var PmsEventoCalendario $evento */
            foreach ($reserva->getEventosCalendario() as $evento) {
                /** @var PmsEventoBeds24Link $link */
                foreach ($evento->getBeds24Links() as $link) {
                    if ($link->isEsPrincipal()) {
                        $targetBookId = $link->getBeds24BookId();
                        break 2;
                    }
                }
            }
        }

        // 🔥 OBTENEMOS EL ID DEL CANAL
        $sourceId = $reserva->getChannel() ? $reserva->getChannel()->getId() : PmsChannel::CODIGO_DIRECTO;

        return [
            'beds24_book_id' => $targetBookId,
            'beds24_config'  => $reserva->getEstablecimiento()?->getBeds24Config(),
            'source'         => $sourceId,
            // ⚠️ **Ya resuelto, no el identificador.** `source` viaja para que las plantillas
            // puedan acotarse por canal —el núcleo lo compara contra lo que alguien configuró,
            // sin entenderlo—, pero «¿hay una plataforma de por medio?» es una CONSECUENCIA, y
            // quien la conoce es el dominio. Publicándola aquí, `MessageFactory` y
            // `Beds24SendEnqueuer` dejan de importar `PmsChannel` para deducirla ellos.
            'es_plataforma'  => $reserva->esDePlataforma(),
        ];
    }

    /**
     * @param string|null $idioma Idioma del CUERPO. Sin él no se compone `bloque_pago`, que es
     *                            la única variable redactada y la única que cuesta consultas.
     *                            Ver el contrato.
     *
     * @return array<string, scalar|null>
     */
    public function getMessageVariables(string $contextId, ?string $idioma = null): array
    {
        $reserva = $this->getReserva($contextId);
        if (!$reserva) {
            return [];
        }

        $canal = $reserva->getChannel();
        $pais = $reserva->getPais();
        $localizador = $reserva->getLocalizador();

        // Los importes salen de la CABECERA FINANCIERA, no de PmsReserva::getMontoTotal():
        // ese campo sólo se rellena en las OTA (y a veces incompleto), así que en una directa
        // la plantilla decía "su total es 0.00". Ver §12.0.2.
        $info = $this->entityManager->getRepository(PmsInformacionFinanciera::class)
            ->findOneBy(['reserva' => $reserva]);

        return [
            'guest_name'            => $reserva->getNombreCliente(),
            'guest_full_name'       => trim($reserva->getNombreCliente() . ' ' . $reserva->getApellidoCliente()),
            'locator'               => $localizador,
            'checkin_date'          => $reserva->getFechaLlegada()?->format('d/m/Y') ?? '',
            'checkout_date'         => $reserva->getFechaSalida()?->format('d/m/Y') ?? '',
            'nights'                => $reserva->getNoches(),
            'pax_total'             => $reserva->getPaxTotal(),
            // 💱 IMPORTES AUTOCONTENIDOS, con su moneda dentro.
            //
            // Con contabilidad por moneda (§12.2b) un importe suelto ya no significa nada: la
            // misma reserva puede deber en soles y en dólares. Estos marcadores pasan a ser
            // cadenas completas —«US$ 65.97 + S/ 50.00»— para que una plantilla no tenga que
            // concatenar la moneda por su cuenta.
            //
            // ⚠️ Por eso `currency` se queda VACÍO cuando hay más de una: una plantilla escrita
            // como «Debe {balance} {currency}» habría renderizado «Debe US$ 65.97 + S/ 50.00 USD».
            //
            // Auditado el 16/08/2026: de las 11 plantillas en base, **ninguna** usa hoy ninguno de
            // estos marcadores en ninguno de sus cuatro canales. El cambio de forma es seguro; si
            // algún día se usan, ya vienen listos para leerse tal cual.
            'total_amount'          => $this->importe($info, 'cargos'),
            'accommodation_amount'  => $info?->getTotalAlojamiento() ?? '0.00',
            'cleaning_fee'          => $info?->getTotalLimpieza() ?? '0.00',
            'service_fee'           => $info?->getTotalServicio() ?? '0.00',
            'paid_amount'           => $this->importe($info, 'pagos'),
            'balance'               => $this->importe($info, 'saldo'),
            'currency'              => $this->monedaUnica($info),
            'property_name'         => $reserva->getNombreHotel(),
            'room_name'             => $reserva->getNombreHabitacion(),
            'channel_name'          => $canal ? $canal->getNombre() : 'Directo',
            'guest_country'         => $pais ? $pais->getNombre() : '',
            'guide_url'             => rtrim($this->paxBookGuideUrl, '/') . '/' . $localizador,
            'guide_path'            => rtrim($this->paxBookGuideUrlNd, '/') . '/' . $localizador,

            // El estado de cuenta. Es **la misma página** que `guide_url` —la tarjeta de cuenta
            // es su primera sección— y lo que cambia es el ancla, o sea a qué llega abierto.
            //
            // Existen como marcadores propios y no como «guide_url más un ancla escrita a mano en
            // la plantilla» porque una plantilla no debería saber cómo se navega el pax: el día
            // que el ancla cambie de nombre habría que perseguirla por siete idiomas de cada
            // plantilla que la use.
            //
            // ⚠️ El ancla del resumen es EXPLÍCITA aunque sea el estado por defecto: quien
            // recibe esto por WhatsApp no ve la página, ve la URL, y un enlace tiene que decir a
            // qué lleva. Ver `docs/PmsBeds24ReservasSync.md` §12.5.2.
            'account_url'           => rtrim($this->paxBookGuideUrl, '/') . '/' . $localizador . '#resumen',
            // ⚠️ La variante SIN dominio, que es la que aceptan los botones `url` de Meta: allí
            // el dominio es fijo en la plantilla aprobada y sólo viaja el sufijo. Sin esta clave,
            // el botón de la plantilla de pago no tenía a dónde apuntar y habría acabado usando
            // `guide_path`, que abre la guía sin el resumen de cuenta desplegado.
            'account_path'          => rtrim($this->paxBookGuideUrlNd, '/') . '/' . $localizador . '#resumen',
            'account_detail_url'    => rtrim($this->paxBookGuideUrl, '/') . '/' . $localizador . '#detalle',
            'tours_catalog_url'     => rtrim($this->paxCatalogUrl, '/'),
            'tours_catalog_path'    => rtrim($this->paxCatalogUrlNd, '/'),
            // ── EL DINERO, YA REDACTADO ─────────────────────────────────────────────
            //
            // Sólo si se pidió idioma: ver el contrato. Es la única variable que no es un dato
            // sino un texto compuesto, y por eso es la única que cuesta consultas.
            //
            // ⚠️ Puede venir VACÍA, y el cuerpo tiene que aguantarlo: con un cruce de monedas sin
            // imputar el read-model calla a propósito. Un cuerpo escrito como «Aquí tienes tu
            // resumen: {{ bloque_pago }}» se queda a medias; la línea de arriba tiene que
            // sostenerse sola.
            'bloque_pago'           => $idioma !== null ? $this->redactor->bloque($reserva, $idioma) : null,
            // ── LAS ESTANCIAS, dichas de verdad ─────────────────────────────────────
            //
            // `checkin_date` y `checkout_date` son el mínimo y el máximo de la reserva, así que
            // con más de una estancia la frase deja de ser cierta: `3DAGPB` saldría «del 28 de
            // agosto al 6 de septiembre» con cuatro noches de hueco dentro. Ver
            // `PmsRedactorDeEstancias`, que agrupa por par de fechas y respeta el idioma.
            'estancias'             => $idioma !== null ? $this->estancias->texto($reserva, $idioma) : null,
        ];
    }

    /**
     * Obtiene un conjunto de variables mixtas (URLs reales + Datos Dummy) para previsualizaciones
     * y para inyectar en el array obligatorio 'example' al crear plantillas en Meta.
     *
     * @return array<string, string|int|float> Diccionario de variables dummy seguras.
     */
    public function getPreviewMessageVariables(): array
    {
        $dummyLocator = 'PREVIEW-123456';
        $now = new DateTimeImmutable();
        $checkout = $now->modify('+4 days');

        return [
            'guest_name'            => 'John',
            'guest_full_name'       => 'John Doe',
            'locator'               => $dummyLocator,
            'checkin_date'          => $now->format('d/m/Y'),
            'checkout_date'         => $checkout->format('d/m/Y'),
            'nights'                => 4,
            'pax_total'             => 2,
            'total_amount'          => '150.00',
            'accommodation_amount'  => '120.00',
            'cleaning_fee'          => '15.00',
            'service_fee'           => '15.00',
            'paid_amount'           => '50.00',
            'balance'               => '100.00',
            'currency'              => 'USD',
            'property_name'         => 'Centro Cusco Inti',
            'room_name'             => 'Casita Principal',
            'channel_name'          => 'Booking.com',
            'guest_country'         => 'Perú',
            'guide_url'             => rtrim($this->paxBookGuideUrl, '/') . '/' . $dummyLocator,
            'guide_path'            => rtrim($this->paxBookGuideUrlNd, '/') . '/' . $dummyLocator,
            // ⚠️ Los marcadores nuevos van TAMBIÉN aquí. Este array alimenta el `example`
            // obligatorio al crear plantillas en Meta: uno que falte se envía vacío y Meta
            // rechaza la plantilla, o peor, la aprueba con un ejemplo que no se parece a nada.
            'account_url'           => rtrim($this->paxBookGuideUrl, '/') . '/' . $dummyLocator . '#resumen',
            // ⚠️ La variante SIN dominio, que es la que aceptan los botones `url` de Meta: allí
            // el dominio es fijo en la plantilla aprobada y sólo viaja el sufijo. Sin esta clave,
            // el botón de la plantilla de pago no tenía a dónde apuntar y habría acabado usando
            // `guide_path`, que abre la guía sin el resumen de cuenta desplegado.
            'account_path'          => rtrim($this->paxBookGuideUrlNd, '/') . '/' . $dummyLocator . '#resumen',
            'account_detail_url'    => rtrim($this->paxBookGuideUrl, '/') . '/' . $dummyLocator . '#detalle',
            'tours_catalog_url'     => rtrim($this->paxCatalogUrl, '/'),
            'tours_catalog_path'    => rtrim($this->paxCatalogUrlNd, '/'),
        ];
    }
}