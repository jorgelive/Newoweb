<?php

declare(strict_types=1);

namespace App\Pms\Service\Message;

use App\Message\Contract\MessageDataResolverInterface;
use App\Pms\Entity\PmsChannel;
use App\Pms\Entity\PmsEventoBeds24Link;
use App\Pms\Entity\PmsEventoCalendario;
use App\Pms\Entity\PmsEstablecimiento;
use App\Pms\Entity\PmsInformacionFinanciera;
use App\Pms\Finanzas\PmsRedactorDeCobro;
use App\Pms\Entity\PmsReserva;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use App\Pms\Enum\PmsEstablecimientoMediaTipo;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AutoconfigureTag('app.message_data_resolver')]
class PmsMessageDataResolver implements MessageDataResolverInterface
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly TelefonoDeContacto $telefonos,
        private readonly PmsRedactorDeCobro $redactor,
        private readonly PmsRedactorDeEstancias $estancias,
        #[Autowire('%pax_host_url%')]
        private string $paxHostUrl,
        #[Autowire('%pax_book_guide_url%')]
        private readonly string $paxBookGuideUrl,
        #[Autowire('%pax_book_guide_url_nd%')]
        private readonly string $paxBookGuideUrlNd,
        #[Autowire('%pax_catalog_url%')]
        private readonly string $paxCatalogUrl,
        #[Autowire('%pax_catalog_url_nd%')]
        private readonly string $paxCatalogUrlNd,
    ) {}

    /**
     * El WhatsApp por el que se atiende, como se escribe y como enlace.
     *
     * Existe para decirle al huésped de Booking a dónde mandar la captura del pago: en el chat de
     * Booking el huésped no puede adjuntar imágenes, aunque nosotros sí
     * (`PmsChannel::CHAT_SIN_IMAGENES`). Se da el NÚMERO y no sólo el enlace porque es lo único
     * comprobado que llega intacto por ese chat —el número de Yape lleva meses saliendo en la
     * bienvenida— y un móvil lo convierte en pulsable igual.
     *
     * ⚠️ **Sale del ESTABLECIMIENTO, no de un parámetro.** Estuvo unas horas en
     * `config/services_parameters.yaml` y era el sitio equivocado: es el teléfono del alojamiento
     * —`telefonoPrincipal`, el mismo que enseña el catálogo público y la tarjeta del anfitrión de
     * la guía—, así que con dos alojamientos cada uno tiene el suyo. El de la AGENCIA es otra
     * cosa y vive en `agencia_telefono_emergencia`.
     *
     * @return array{whatsapp_numero: string, whatsapp_url: string, emergencia_numero: string, emergencia_url: string}
     */
    private function whatsappDelAlojamiento(?PmsEstablecimiento $establecimiento): array
    {
        $wa = static fn (string $numero): string => $numero === ''
            ? ''
            : 'https://wa.me/' . preg_replace('/\D/', '', $numero);

        $publico = trim((string) $establecimiento?->getTelefonoPrincipal());
        // El de urgencias sale con el MISMO nombre que en la guía (`PmsGuiaContexto`): es el
        // mismo dato y el mismo editor escribe en los dos sitios.
        $emergencia = trim((string) $establecimiento?->getTelefonoEmergencia());

        return [
            'whatsapp_numero' => $publico,
            'whatsapp_url' => $wa($publico),
            'emergencia_numero' => $emergencia,
            'emergencia_url' => $wa($emergencia),
        ];
    }

    /**
     * `https://wa.me/<numero>?text=Hola,%20soy%20…%20reserva%20ABC123`
     *
     * El huésped le da al enlace, se le abre WhatsApp con el mensaje escrito y sólo tiene que
     * enviarlo. Ese mensaje trae su localizador, que es lo que {@see ReservaPorLocalizador} usa
     * para saber de quién es un número que Booking ya no nos manda.
     *
     * ⚠️ **El texto va en SU idioma.** Lo lee antes de enviarlo: si le aparece un «Hola, soy…»
     * en español a alguien que escribe en inglés, lo borra y escribe lo suyo — y con eso se
     * pierde el localizador, que es justo lo único que había que conservar.
     *
     * Cadena vacía si el alojamiento no tiene teléfono: un `wa.me/` sin número es un enlace roto,
     * y el hidratador ya sabe que lo vacío desaparece.
     */
    private function enlaceDeWhatsappConLocalizador(
        string $numero,
        PmsReserva $reserva,
        ?string $localizador,
        string $idioma
    ): string {
        $numero = preg_replace('/\D/', '', $numero) ?? '';

        if ($numero === '' || ($localizador ?? '') === '') {
            return '';
        }

        $nombre = trim((string) $reserva->getNombreCliente());

        $plantilla = match ($idioma) {
            'en' => 'Hi, I am %s, booking %s',
            'pt' => 'Olá, sou %s, reserva %s',
            'fr' => 'Bonjour, je suis %s, réservation %s',
            'it' => 'Ciao, sono %s, prenotazione %s',
            'de' => 'Hallo, ich bin %s, Buchung %s',
            'nl' => 'Hallo, ik ben %s, boeking %s',
            default => 'Hola, soy %s, reserva %s',
        };

        return sprintf(
            'https://wa.me/%s?text=%s',
            $numero,
            rawurlencode(sprintf($plantilla, $nombre, $localizador))
        );
    }

    /**
     * 🔒 Las claves que ABREN ALGO y no pueden viajar por el mero hecho de estar en el diccionario.
     *
     * ── Por qué existe esta lista ───────────────────────────────────────────
     * `getMessageVariables()` nació para rellenar PLANTILLAS, que las manda un operador. Quien la
     * consume además de las plantillas —{@see \App\Agent\Skill\Pms\ConsultarMiReservaSkill}— la
     * vuelca entera al modelo, y eso estuvo bien mientras aquí sólo hubiera fechas, importes y
     * enlaces.
     *
     * 🔥 **Dejó de estarlo el 12/09/2026**, cuando entraron los códigos de las dos cajas y los
     * medios de la del dinero: un huésped preguntando «¿cuál es mi reserva?» recibía los dos
     * códigos y la foto de dónde se deja el efectivo, sin ventana y sin haber pagado. Justo lo que
     * `PmsGuiaAcceso` y `ConsultarCodigosSkill` llevan semanas cuidando por los otros caminos.
     *
     * ⚠️ **La lista vive aquí y no en la skill** porque quien añade una clave nueva edita ESTE
     * archivo. Una lista en el consumidor es una lista que el que amplía el diccionario no ve.
     *
     * Las plantillas SÍ las usan —`caja_dinero` no existe sin ellas—: ahí el filtro es quién puede
     * mandarlas, `ROLE_MENSAJES_WRITE` sobre `enviar_plantilla`.
     */
    public const array CLAVES_DE_ACCESO = [
        'codigo_caja_llaves',
        'codigo_caja_dinero',
        'foto_caja_llaves',
        'foto_caja_dinero',
        'video_caja_llaves',
        'video_caja_dinero',
    ];

    /**
     * Los códigos de las dos cajas y sus fotos y vídeos, como enlace.
     *
     * ── Por qué son variables y no se pegan en la plantilla ─────────────────
     * Porque el archivo se reemplaza y la URL cambia: `MediaTokenNamer` le da un nombre nuevo y
     * Vich borra el viejo (`delete_on_update`). Una URL tecleada dentro de una plantilla está
     * copiada en cuatro canales × siete idiomas, y el día del reemplazo las veintiocho apuntan a
     * un 404 sin que nada avise. Así la plantilla escribe `{{ foto_caja_dinero }}` y el valor se
     * resuelve al enviar.
     *
     * ⚠️ **Todo lo que sale de aquí está en {@see self::CLAVES_DE_ACCESO}**, porque abre algo. Lo
     * usan las plantillas, que manda un operador con `ROLE_MENSAJES_WRITE`; quien lea este
     * diccionario para otra cosa tiene que restarlas.
     *
     * ⚠️ **Las URL, absolutas y con el host de `pax`.** El listener deja una ruta (`/carga/…`),
     * que la web resuelve contra su origen; esto acaba en un WhatsApp, donde no hay origen. Se usa
     * el host de `pax` porque es el que el huésped ya ve en el enlace de su guía.
     *
     * Un medio que no se ha subido sale como cadena vacía, igual que el WhatsApp: la plantilla
     * tiene que sostenerse sin él.
     *
     * @return array<string, string>
     */
    private function mediosDelAlojamiento(?PmsEstablecimiento $establecimiento): array
    {
        // 🔑 Los códigos de las DOS cajas, que hasta ahora no viajaban por plantilla.
        //
        // Sin ellos no se puede escribir la plantilla del dinero: decirle a alguien que deje un
        // pago en una caja sin darle el código es no decirle nada. El de las llaves ya sale en la
        // guía (`{{ codigo_caja_llaves }}`); el de la otra caja **no lo usaba nadie**.
        //
        // ⚠️ Van con el nombre de lo que son —llaves y dinero—, no «principal» y «secundaria».
        // Un nombre que no dice qué abre es el patrón del `{{ door_code }}` que acabó anunciando
        // «el código de la puerta es #5». La correspondencia es la del ítem «Llaves (general)»:
        // las llaves están en la caja de abajo y su código es el principal.
        $salida = [
            'codigo_caja_llaves' => (string) ($establecimiento?->getCodigoCajaLlaves() ?? ''),
            'codigo_caja_dinero' => (string) ($establecimiento?->getCodigoCajaDinero() ?? ''),
        ];

        foreach (PmsEstablecimientoMediaTipo::cases() as $tipo) {
            $valor = (string) ($establecimiento?->medio($tipo)?->getValor() ?? '');

            if ($valor !== '' && !str_starts_with($valor, 'http')) {
                $valor = rtrim($this->paxHostUrl, '/') . '/' . ltrim($valor, '/');
            }

            $salida[$tipo->clave()] = $valor;
        }

        return $salida;
    }

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
     * El idioma de la reserva, o español.
     *
     * `es` y no el del establecimiento porque es el idioma en el que están escritos los originales
     * de todo —plantillas y fichas— y el único que no puede faltar. Un huésped sin idioma guardado
     * es alguien de quien no sabemos nada: se le habla en el idioma de la casa.
     */
    private function idiomaDe(PmsReserva $reserva): string
    {
        $idioma = $reserva->getIdioma()?->getId();

        return $idioma !== null && $idioma !== '' ? strtolower($idioma) : 'es';
    }

    /**
     * @param string|null $idioma Idioma del CUERPO, que manda cuando se pasa: es distinto del
     *                            idioma del huésped cuando el suyo no está entre los siete y la
     *                            plantilla cae al inglés. **Sin él se usa el de la reserva, y si
     *                            tampoco lo hay, español.**
     *
     * @return array<string, scalar|null>
     */
    public function getMessageVariables(string $contextId, ?string $idioma = null): array
    {
        $reserva = $this->getReserva($contextId);
        if (!$reserva) {
            return [];
        }

        // 🗣️ SIN IDIOMA NO SE DEVUELVE MEDIO DICCIONARIO: se usa el de la reserva.
        //
        // Las cuatro variables redactadas —`bloque_pago`, `estancias`, `importe_a_pagar`,
        // `medios_de_pago`— salían `null` cuando quien llamaba no pasaba idioma, y `null` se
        // sustituye por NADA. Quien no lo pasaba no era un caso raro:
        //
        // | Quién | Qué salía |
        // |---|---|
        // | `PmsReservaWhatsappLinkController` (el envío a mano desde el calendario) | «Tu reserva:» seguido de un hueco, y el bloque de pago entero vacío |
        // | `BuscarReservaSkill` / `ConsultarMiReservaSkill` | el agente leía la reserva sin sus estancias |
        //
        // Se vio el 17/09/2026 en una captura de WhatsApp: «Detalle de pago» enviada a mano a una
        // huésped, con los dos huecos. El envío automático nunca falló porque las estrategias sí
        // pasan el idioma de la plantilla.
        //
        // ⚠️ Componer cuesta consultas, y por eso antes se evitaba «por si acaso». Pero un
        // diccionario a medias no ahorra: fabrica mensajes incompletos que nadie ve venir.
        $idioma ??= $this->idiomaDe($reserva);

        $canal = $reserva->getChannel();
        $pais = $reserva->getPais();
        $localizador = $reserva->getLocalizador();

        // Los importes salen de la CABECERA FINANCIERA, no de PmsReserva::getMontoTotal():
        // ese campo sólo se rellena en las OTA (y a veces incompleto), así que en una directa
        // la plantilla decía "su total es 0.00". Ver §12.0.2.
        $info = $this->entityManager->getRepository(PmsInformacionFinanciera::class)
            ->findOneBy(['reserva' => $reserva]);

        // Una vez: lo usan `account_url` y la tarjeta de `medios_de_pago`, y tienen que ser el
        // mismo enlace.
        $accountUrl = rtrim($this->paxBookGuideUrl, '/') . '/' . $localizador . '#resumen';

        // Se resuelve aquí y no sólo al final porque `whatsapp_enlace_reserva` lo necesita para
        // componerse; el `+` de abajo lo sigue añadiendo igual y el array no se duplica.
        $contacto = $this->whatsappDelAlojamiento($reserva->getEstablecimiento());

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
            // 📲 EL ENLACE CON EL MENSAJE YA ESCRITO, y su localizador dentro.
            //
            // Nace por el aviso de Booking del 28/09/2026: dejan de transmitir el teléfono del
            // huésped, así que un WhatsApp suyo llegaría de un número que no sabemos de quién
            // es. Con este enlace, el primer mensaje que nos manda TRAE su localizador y
            // `ReservaPorLocalizador` lo casa con su reserva sin que nadie teclee nada.
            //
            // El texto va en el idioma del huésped: lo lee él antes de darle a enviar, y un
            // «Hola, soy…» en español a quien escribe en inglés se borra y se pierde el código.
            'whatsapp_enlace_reserva' => $this->enlaceDeWhatsappConLocalizador(
                $contacto['whatsapp_numero'],
                $reserva,
                $localizador,
                $idioma
            ),
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
            'account_url'           => $accountUrl,
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
            'bloque_pago'           => $this->redactor->bloque($reserva, $idioma),
            // ── LAS ESTANCIAS, dichas de verdad ─────────────────────────────────────
            //
            // `checkin_date` y `checkout_date` son el mínimo y el máximo de la reserva, así que
            // con más de una estancia la frase deja de ser cierta: `3DAGPB` saldría «del 28 de
            // agosto al 6 de septiembre» con cuatro noches de hueco dentro. Ver
            // `PmsRedactorDeEstancias`, que agrupa por par de fechas y respeta el idioma.
            'estancias'             => $this->estancias->texto($reserva, $idioma),
            // El mismo dato del bloque, en UNA línea: es lo único de todo esto que cabe en un
            // parámetro de plantilla de Meta. Ver `PmsRedactorDeCobro::importeAPagar()`.
            'importe_a_pagar'       => $this->redactor->importeAPagar($reserva),
            // Los datos para pagar, escritos. Es para la plantilla de políticas de Booking, donde
            // las cuentas TIENEN que ir en el texto porque su trabajo es dejar constancia en el
            // chat de la OTA. Salen del catálogo y no tecleadas: así el filtro de audiencia se
            // aplica solo —un europeo ve Western Union, no una cuenta peruana— y cuando cambie un
            // número, cambia el mensaje. Ver `PmsRedactorDeCobro::mediosConDatos()`.
            // La tarjeta entra en la lista con el enlace a la ficha, que es donde vive el cobro
            // vigente — ver `mediosConDatos()`.
            'medios_de_pago'        => $this->redactor->mediosConDatos($reserva, $idioma, enlaceTarjeta: $accountUrl),
            // La versión LARGA, con todas las cuentas. No es para el mensaje de siempre —sería
            // una sábana— sino para cuando hay que demostrarle a la OTA que se dio la
            // información completa. Se manda a mano desde el panel, no por una regla.
            'medios_de_pago_todos'  => $this->redactor->mediosConDatos($reserva, $idioma, todas: true, enlaceTarjeta: $accountUrl),
        ] + $this->whatsappDelAlojamiento($reserva->getEstablecimiento())
          + $this->mediosDelAlojamiento($reserva->getEstablecimiento());
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
            'codigo_caja_llaves'    => '4074E',
            'codigo_caja_dinero'    => '2013E',
            'foto_caja_llaves'      => rtrim($this->paxHostUrl, '/') . '/carga/pms/pms_establecimiento/images/ejemplo.webp',
            'foto_caja_dinero'      => rtrim($this->paxHostUrl, '/') . '/carga/pms/pms_establecimiento/images/ejemplo.webp',
            'video_caja_llaves'     => 'https://youtu.be/ejemplo',
            'video_caja_dinero'     => 'https://youtu.be/ejemplo',
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
        ] + $this->whatsappDelAlojamiento(null);
    }
}