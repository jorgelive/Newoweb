<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Symfony\Component\Uid\Uuid;

/**
 * Los cuatro temas más preguntados que no estaban en ninguna guía, y el equipaje que sólo veía
 * el agente.
 *
 * Sale de contar sobre qué escriben de verdad los huéspedes en 2026 y cruzarlo con los 42 ítems
 * publicados. Cuatro temas grandes no tenían **nada** —ni ítem, ni término, ni plantilla— y se
 * contestaban a mano una y otra vez:
 *
 * | Tema | Menciones 2026 | Había |
 * |---|---|---|
 * | Tours | 62 | plantilla `menu_tours` (se queda como está) |
 * | Equipaje | 56 | sólo `agente_contenido`, invisible en la guía |
 * | Cancelación / estadía mínima | 27 | nada |
 * | Taxi / aeropuerto | 25 | nada |
 * | Estacionamiento | 20 | nada |
 * | Boleta / factura | 18 | nada |
 *
 * ### El texto no me lo he inventado
 *
 * Cada afirmación sale de respuestas reales del equipo en el chat, no de un supuesto razonable.
 * El estacionamiento se describe igual en seis conversaciones distintas; lo del IGV y la boleta
 * es una frase textual («no cobramos igv y damos boleta electrónica a todos los huéspedes pero
 * no factura»); el recojo de aeropuerto de Booking y el mínimo de dos noches, también.
 *
 * ⚠️ **Lo que NO se afirma, a propósito:** que guardar el equipaje sea gratis. Se presta siempre
 * y nadie lo cobró nunca, pero tampoco se dijo jamás «es gratis» por escrito, así que el ítem no
 * lo promete y el campo del agente mantiene su «si pregunta el costo, avisa al equipo».
 *
 * ⚠️ **Cancelación:** el «30 días / primera noche» viene de una sola frase del equipo y **las OTA
 * imponen la suya por plan tarifario**. Por eso el ítem va en visibilidad `cliente` y no
 * `publico`, cierra remitiendo a las condiciones de la propia reserva, y el campo del agente le
 * prohíbe expresamente contradecir a la plataforma y le obliga a escalar cualquier cancelación
 * en curso. Si la política directa es otra, se corrige en el panel: es un texto, no código.
 *
 * ### Dónde entra cada uno
 *
 * En las siete `Ingreso a la casa`, encadenados justo detrás de «Ubicación», que es el orden en
 * que se leen: dónde está → cómo llego → dónde dejo el coche → llaves → puerta.
 *
 * En `Pagos y Reglamento (general)` —una sola sección que cuelga de las siete guías— detrás de
 * «Pago»: primero cómo se paga, luego qué comprobante hay, luego qué pasa si cancelas.
 *
 * ### Por qué las secciones se buscan por `nombre_interno`
 *
 * {@see Version20260810160000} las localizó por `JSON_EXTRACT(titulo, '$[0].content')`. Funciona
 * hoy, pero da por hecho que el bloque español es el primero del array, y el orden de los
 * bloques i18n no lo garantiza nadie: basta que el traductor reescriba el JSON para que `[0]`
 * pase a ser el inglés y la migración no encuentre nada — en silencio, sin error. `nombre_interno`
 * es el identificador interno y no se traduce.
 *
 * ### Los siete idiomas van escritos, no traducidos
 *
 * Una migración inserta con SQL crudo, así que **no dispara los listeners de Doctrine**: el
 * `#[AutoTranslate]` de `PmsGuiaItem::$titulo` y `$descripcion` no llega a ejecutarse. Si aquí
 * fuera sólo el español, los otros seis idiomas se quedarían vacíos hasta que alguien reeditara
 * el ítem en el panel. Por eso van los siete escritos, y `sobreescribir_traduccion = 0` para que
 * una edición futura del español no los machaque.
 *
 * Idempotente en sus tres partes: no duplica ítems, no reenlaza secciones que ya lo tienen y no
 * vuelve a pegar el párrafo del equipaje.
 *
 * Ver docs/PmsGuiaHuesped.md.
 */
final class Version20260811160000 extends AbstractMigration
{
    /**
     * Los cuatro ítems nuevos, en el orden en que deben quedar dentro de su sección.
     *
     * `tras` encadena: «Estacionamiento» va detrás de «Traslados», que a su vez va detrás de
     * «Ubicación». Se resuelve en memoria, no consultando la base — cuando llega el turno del
     * segundo, el primero todavía no está insertado.
     *
     * @var array<string, array{
     *     icono: string, visibilidad: string, seccion: string, tras: string,
     *     terminos: string, agente: string,
     *     titulo: array<string, string>, cuerpo: array<string, string>
     * }>
     */
    private const array ITEMS = [
        'Traslados (general)' => [
            'icono' => 'fa-taxi',
            'visibilidad' => 'publico',
            'seccion' => 'ingreso',
            'tras' => 'Ubicación (general)',
            'terminos' => 'taxi, uber, aeropuerto, traslado, recojo, recogida, transporte, como llego, '
                . 'movilidad, van, chofer, pickup, airport, transfer, transfert, navette, flughafen, '
                . 'luchthaven, aeroporto',
            'agente' => "NO tenemos traslado propio: no lo prometas nunca ni digas que mandamos a alguien.

Uber funciona en Cusco y hay taxis dentro del aeropuerto; basta con que den la direccion. El
coche llega hasta la puerta: la calle es plana y ancha, sin escaleras ni ultimo tramo a pie. Eso
tranquiliza a quien viene con equipaje pesado o con personas mayores, y conviene decirlo aunque
no lo pregunte.

Si la reserva es de Booking y pregunta por el recojo en aeropuerto: lo ofrece Booking a sus
clientes preferentes, no nosotros. Que les pase a ELLOS sus datos de vuelo. Nosotros no podemos
gestionarlo ni confirmarlo, asi que no digas que lo revisas.",
            'titulo' => [
                'es' => 'Traslados y taxi',
                'en' => 'Transfers and taxis',
                'pt' => 'Traslados e táxi',
                'fr' => 'Transferts et taxi',
                'de' => 'Transfers und Taxi',
                'it' => 'Transfer e taxi',
                'nl' => 'Vervoer en taxi',
            ],
            'cuerpo' => [
                'es' => "<p>🚕 <strong>No ofrecemos servicio de traslado propio</strong>, pero llegar es sencillo.</p>\n"
                    . "<p>✈️ Hay <strong>taxis dentro del aeropuerto</strong>, y <strong>Uber funciona en Cusco</strong>: basta con dar la dirección de la casita.</p>\n"
                    . "<p>🚗 El vehículo te deja <strong>en la puerta</strong>: la calle es plana y ancha, sin escaleras ni tramos a pie.</p>\n"
                    . "<p>🏨 Si reservaste por <strong>Booking.com</strong>, el recojo en el aeropuerto lo ofrece la propia plataforma a sus clientes preferentes. Se coordina <strong>con ellos</strong>, enviándoles tus datos de vuelo.</p>",
                'en' => "<p>🚕 <strong>We do not offer our own transfer service</strong>, but getting here is easy.</p>\n"
                    . "<p>✈️ There are <strong>taxis inside the airport</strong>, and <strong>Uber works in Cusco</strong>: just give the address of the house.</p>\n"
                    . "<p>🚗 The vehicle drops you <strong>right at the door</strong>: the street is flat and wide, with no stairs and no walking stretch.</p>\n"
                    . "<p>🏨 If you booked through <strong>Booking.com</strong>, the airport pick-up is offered by the platform itself to its preferred customers. You arrange it <strong>with them</strong>, by sending them your flight details.</p>",
                'pt' => "<p>🚕 <strong>Não oferecemos serviço de traslado próprio</strong>, mas chegar é simples.</p>\n"
                    . "<p>✈️ Há <strong>táxis dentro do aeroporto</strong> e o <strong>Uber funciona em Cusco</strong>: basta indicar o endereço da casa.</p>\n"
                    . "<p>🚗 O veículo deixa-o <strong>à porta</strong>: a rua é plana e larga, sem escadas nem trechos a pé.</p>\n"
                    . "<p>🏨 Se reservou pela <strong>Booking.com</strong>, a recolha no aeroporto é oferecida pela própria plataforma aos seus clientes preferenciais. Combina-se <strong>com eles</strong>, enviando os seus dados de voo.</p>",
                'fr' => "<p>🚕 <strong>Nous ne proposons pas de service de transfert</strong>, mais l'accès est simple.</p>\n"
                    . "<p>✈️ Il y a des <strong>taxis à l'intérieur de l'aéroport</strong>, et <strong>Uber fonctionne à Cusco</strong> : il suffit de donner l'adresse de la maison.</p>\n"
                    . "<p>🚗 Le véhicule vous dépose <strong>devant la porte</strong> : la rue est plate et large, sans escaliers ni portion à pied.</p>\n"
                    . "<p>🏨 Si vous avez réservé via <strong>Booking.com</strong>, le transfert depuis l'aéroport est proposé par la plateforme elle-même à ses clients privilégiés. Cela s'organise <strong>avec eux</strong>, en leur envoyant vos informations de vol.</p>",
                'de' => "<p>🚕 <strong>Wir bieten keinen eigenen Transferservice an</strong>, die Anreise ist aber unkompliziert.</p>\n"
                    . "<p>✈️ Es gibt <strong>Taxis direkt im Flughafen</strong>, und <strong>Uber funktioniert in Cusco</strong>: Geben Sie einfach die Adresse des Hauses an.</p>\n"
                    . "<p>🚗 Das Fahrzeug setzt Sie <strong>direkt vor der Tür</strong> ab: Die Straße ist eben und breit, ohne Treppen und ohne Fußweg.</p>\n"
                    . "<p>🏨 Wenn Sie über <strong>Booking.com</strong> gebucht haben, wird die Abholung am Flughafen von der Plattform selbst für ihre bevorzugten Kunden angeboten. Sie stimmen das <strong>mit ihnen</strong> ab, indem Sie ihnen Ihre Flugdaten senden.</p>",
                'it' => "<p>🚕 <strong>Non offriamo un servizio di transfer nostro</strong>, ma arrivare è semplice.</p>\n"
                    . "<p>✈️ Ci sono <strong>taxi all'interno dell'aeroporto</strong> e <strong>Uber funziona a Cusco</strong>: basta indicare l'indirizzo della casa.</p>\n"
                    . "<p>🚗 Il veicolo ti lascia <strong>davanti alla porta</strong>: la strada è pianeggiante e larga, senza scale né tratti a piedi.</p>\n"
                    . "<p>🏨 Se hai prenotato tramite <strong>Booking.com</strong>, il transfer dall'aeroporto è offerto dalla piattaforma stessa ai suoi clienti preferenziali. Si concorda <strong>con loro</strong>, inviando i dati del tuo volo.</p>",
                'nl' => "<p>🚕 <strong>Wij bieden geen eigen vervoersservice aan</strong>, maar hier komen is eenvoudig.</p>\n"
                    . "<p>✈️ Er zijn <strong>taxi's in de luchthaven</strong> en <strong>Uber werkt in Cusco</strong>: geef gewoon het adres van het huis op.</p>\n"
                    . "<p>🚗 De auto zet u <strong>voor de deur</strong> af: de straat is vlak en breed, zonder trappen of stukken te voet.</p>\n"
                    . "<p>🏨 Heeft u via <strong>Booking.com</strong> geboekt, dan wordt de afhaalservice op de luchthaven door het platform zelf aangeboden aan hun voorkeursklanten. Dat regelt u <strong>met hen</strong>, door hun uw vluchtgegevens te sturen.</p>",
            ],
        ],

        'Estacionamiento (general)' => [
            'icono' => 'fa-square-parking',
            'visibilidad' => 'publico',
            'seccion' => 'ingreso',
            'tras' => 'Traslados (general)',
            'terminos' => 'estacionamiento, parqueo, cochera, parking, auto, carro, coche, vehiculo, '
                . 'camioneta, moto, garaje, donde dejo el auto, stationnement, parcheggio, parkplatz, '
                . 'parkeren, estacionamento',
            'agente' => "Dos opciones, y ninguna de las dos es nuestra: justo al frente hay una playa de
estacionamiento publica y GRATUITA, y a una cuadra una cochera privada DE PAGO.

Ofrece las dos con confianza, es de lo que mas preguntan los que llegan en coche.

Di siempre que la gratuita no es un area vigilada. Es honesto, y nos evita el reclamo si le pasa
algo al vehiculo.

No sabemos el precio de la cochera ni podemos reservar sitio en ninguna de las dos. Si lo pide,
escala en vez de estimar.",
            'titulo' => [
                'es' => 'Estacionamiento',
                'en' => 'Parking',
                'pt' => 'Estacionamento',
                'fr' => 'Stationnement',
                'de' => 'Parkmöglichkeiten',
                'it' => 'Parcheggio',
                'nl' => 'Parkeren',
            ],
            'cuerpo' => [
                'es' => "<p>🅿️ <strong>Justo al frente de la casita</strong> hay una playa de estacionamiento público <strong>gratuita</strong>, donde muchos huéspedes dejan su vehículo.</p>\n"
                    . "<p>🔒 A <strong>una cuadra</strong> hay una cochera privada <strong>de pago</strong>, si prefieres dejarlo bajo vigilancia.</p>\n"
                    . "<p>❗ Ten en cuenta que la playa gratuita <strong>no es un área vigilada</strong>. No dejes objetos de valor a la vista.</p>",
                'en' => "<p>🅿️ <strong>Right across from the house</strong> there is a <strong>free</strong> public parking lot, where many of our guests leave their vehicle.</p>\n"
                    . "<p>🔒 <strong>One block away</strong> there is a private <strong>paid</strong> garage, if you would rather leave it guarded.</p>\n"
                    . "<p>❗ Please note that the free lot <strong>is not a supervised area</strong>. Do not leave valuables in sight.</p>",
                'pt' => "<p>🅿️ <strong>Mesmo em frente à casa</strong> há um estacionamento público <strong>gratuito</strong>, onde muitos hóspedes deixam o seu veículo.</p>\n"
                    . "<p>🔒 A <strong>uma quadra</strong> há uma garagem privada <strong>paga</strong>, caso prefira deixá-lo vigiado.</p>\n"
                    . "<p>❗ Tenha em conta que o estacionamento gratuito <strong>não é uma área vigiada</strong>. Não deixe objetos de valor à vista.</p>",
                'fr' => "<p>🅿️ <strong>Juste en face de la maison</strong> se trouve un parking public <strong>gratuit</strong>, où de nombreux voyageurs laissent leur véhicule.</p>\n"
                    . "<p>🔒 À <strong>une rue de là</strong>, il y a un garage privé <strong>payant</strong>, si vous préférez le laisser sous surveillance.</p>\n"
                    . "<p>❗ Notez que le parking gratuit <strong>n'est pas une zone surveillée</strong>. Ne laissez pas d'objets de valeur en vue.</p>",
                'de' => "<p>🅿️ <strong>Direkt gegenüber dem Haus</strong> gibt es einen <strong>kostenlosen</strong> öffentlichen Parkplatz, auf dem viele unserer Gäste ihr Fahrzeug abstellen.</p>\n"
                    . "<p>🔒 <strong>Eine Straße weiter</strong> befindet sich eine private, <strong>kostenpflichtige</strong> Garage, falls Sie es bewacht abstellen möchten.</p>\n"
                    . "<p>❗ Bitte beachten Sie: Der kostenlose Parkplatz <strong>ist nicht bewacht</strong>. Lassen Sie keine Wertsachen sichtbar liegen.</p>",
                'it' => "<p>🅿️ <strong>Proprio di fronte alla casa</strong> c'è un parcheggio pubblico <strong>gratuito</strong>, dove molti ospiti lasciano il proprio veicolo.</p>\n"
                    . "<p>🔒 A <strong>un isolato</strong> si trova un garage privato <strong>a pagamento</strong>, se preferisci lasciarlo sorvegliato.</p>\n"
                    . "<p>❗ Tieni presente che il parcheggio gratuito <strong>non è un'area sorvegliata</strong>. Non lasciare oggetti di valore in vista.</p>",
                'nl' => "<p>🅿️ <strong>Recht tegenover het huis</strong> ligt een <strong>gratis</strong> openbare parkeerplaats, waar veel gasten hun voertuig achterlaten.</p>\n"
                    . "<p>🔒 Op <strong>één straat afstand</strong> is er een privégarage <strong>tegen betaling</strong>, als u het liever bewaakt achterlaat.</p>\n"
                    . "<p>❗ Let op: de gratis parkeerplaats <strong>is niet bewaakt</strong>. Laat geen waardevolle spullen in het zicht liggen.</p>",
            ],
        ],

        'Facturación (general)' => [
            'icono' => 'fa-receipt',
            'visibilidad' => 'cliente',
            'seccion' => 'pagos',
            'tras' => 'Pago (general)',
            'terminos' => 'factura, boleta, comprobante, ruc, igv, iva, recibo, documento de pago, '
                . 'invoice, receipt, facture, rechnung, fattura, ricevuta, bon',
            'agente' => "Boleta de venta electronica si, factura NO, y no cobramos IGV: el precio publicado es el
final.

Si piden factura con RUC, dilo claro y sin rodeos: no la emitimos. No prometas consultarlo ni
des esperanzas de que se pueda hacer una excepcion.

Para emitir la boleta hace falta la foto del documento y a nombre de quien va. Se envia por
correo. Si ya termino la estancia y reclama que no le llego, escala.",
            'titulo' => [
                'es' => 'Boleta y comprobantes',
                'en' => 'Receipts and invoices',
                'pt' => 'Recibos e comprovativos',
                'fr' => 'Reçus et factures',
                'de' => 'Belege und Rechnungen',
                'it' => 'Ricevute e fatture',
                'nl' => 'Bonnen en facturen',
            ],
            'cuerpo' => [
                'es' => "<p>🧾 Emitimos <strong>boleta de venta electrónica</strong> a todos los huéspedes. No emitimos factura.</p>\n"
                    . "<p>💵 <strong>No cobramos IGV</strong>: el precio que ves es el precio final.</p>\n"
                    . "<p>📄 Para emitirla necesitamos la <strong>foto de tu documento</strong> y saber <strong>a nombre de quién</strong> va. La recibirás por correo.</p>",
                'en' => "<p>🧾 We issue an <strong>electronic sales receipt</strong> (boleta de venta electrónica) to every guest. We do not issue tax invoices.</p>\n"
                    . "<p>💵 <strong>We do not charge VAT</strong>: the price you see is the final price.</p>\n"
                    . "<p>📄 To issue it we need a <strong>photo of your ID</strong> and the <strong>name it should be made out to</strong>. You will receive it by email.</p>",
                'pt' => "<p>🧾 Emitimos <strong>recibo de venda eletrónico</strong> (boleta de venta electrónica) a todos os hóspedes. Não emitimos fatura.</p>\n"
                    . "<p>💵 <strong>Não cobramos IGV</strong> (o IVA peruano): o preço que vê é o preço final.</p>\n"
                    . "<p>📄 Para o emitir precisamos da <strong>foto do seu documento</strong> e de saber <strong>em nome de quem</strong> deve ser passado. Vai recebê-lo por e-mail.</p>",
                'fr' => "<p>🧾 Nous délivrons un <strong>reçu de vente électronique</strong> (boleta de venta electrónica) à tous les voyageurs. Nous n'émettons pas de facture.</p>\n"
                    . "<p>💵 <strong>Nous ne facturons pas la TVA</strong> : le prix affiché est le prix final.</p>\n"
                    . "<p>📄 Pour l'établir, nous avons besoin d'une <strong>photo de votre pièce d'identité</strong> et du <strong>nom auquel</strong> il doit être établi. Vous le recevrez par e-mail.</p>",
                'de' => "<p>🧾 Wir stellen allen Gästen einen <strong>elektronischen Verkaufsbeleg</strong> (boleta de venta electrónica) aus. Eine Rechnung stellen wir nicht aus.</p>\n"
                    . "<p>💵 <strong>Wir erheben keine Mehrwertsteuer</strong>: Der angezeigte Preis ist der Endpreis.</p>\n"
                    . "<p>📄 Dafür benötigen wir ein <strong>Foto Ihres Ausweises</strong> und den <strong>Namen, auf den</strong> der Beleg ausgestellt werden soll. Sie erhalten ihn per E-Mail.</p>",
                'it' => "<p>🧾 Emettiamo una <strong>ricevuta di vendita elettronica</strong> (boleta de venta electrónica) per tutti gli ospiti. Non emettiamo fattura.</p>\n"
                    . "<p>💵 <strong>Non applichiamo l'IVA</strong>: il prezzo che vedi è il prezzo finale.</p>\n"
                    . "<p>📄 Per emetterla ci servono la <strong>foto del tuo documento</strong> e il <strong>nome a cui</strong> intestarla. La riceverai via e-mail.</p>",
                'nl' => "<p>🧾 Wij geven alle gasten een <strong>elektronisch verkoopbewijs</strong> (boleta de venta electrónica). Een factuur geven wij niet uit.</p>\n"
                    . "<p>💵 <strong>Wij rekenen geen btw</strong>: de prijs die u ziet is de eindprijs.</p>\n"
                    . "<p>📄 Om het op te maken hebben wij een <strong>foto van uw identiteitsbewijs</strong> nodig en de <strong>naam waarop</strong> het moet staan. U ontvangt het per e-mail.</p>",
            ],
        ],

        'Cancelación (general)' => [
            'icono' => 'fa-calendar-xmark',
            'visibilidad' => 'cliente',
            'seccion' => 'pagos',
            'tras' => 'Facturación (general)',
            'terminos' => 'cancelar, cancelacion, anular, reembolso, devolucion, penalidad, '
                . 'cambiar fechas, modificar reserva, minimo de noches, estadia minima, una noche, '
                . 'refund, cancellation, annulation, stornierung, annullamento, annuleren',
            'agente' => "Minimo 2 noches. Si consultar_disponibilidad devuelve vacio para una sola noche, la causa
casi siempre es esta: dilo, en vez de dejarlo en un \"no hay disponibilidad\" que suena a lleno.

Cancelacion directa: gratis hasta 30 dias antes de la llegada, despues se cobra la primera
noche. El prepago de la primera noche es lo que activa esa condicion.

IMPORTANTE: si la reserva viene de una OTA, manda lo que diga SU reserva, no esto. No compares
las dos politicas ni contradigas a la plataforma.

Ante una cancelacion ya en curso, una peticion de reembolso o un cambio de fechas de algo ya
pagado: ESCALA. No la aceptes ni la niegues tu.",
            'titulo' => [
                'es' => 'Cancelaciones y estadía mínima',
                'en' => 'Cancellations and minimum stay',
                'pt' => 'Cancelamentos e estadia mínima',
                'fr' => 'Annulations et séjour minimum',
                'de' => 'Stornierungen und Mindestaufenthalt',
                'it' => 'Cancellazioni e soggiorno minimo',
                'nl' => 'Annuleringen en minimumverblijf',
            ],
            'cuerpo' => [
                'es' => "<p>🗓️ La estadía mínima es de <strong>dos noches</strong>. Si buscas una sola noche no aparecerá disponibilidad: no es que esté ocupado.</p>\n"
                    . "<p>✅ La cancelación es <strong>gratuita hasta 30 días antes</strong> de la llegada. A partir de ahí se cobra una penalidad equivalente a <strong>la primera noche</strong>.</p>\n"
                    . "<p>💳 El prepago de la primera noche es lo que <strong>activa y garantiza</strong> estas condiciones. El saldo se paga al llegar. 🔑</p>\n"
                    . "<p>🏨 Si reservaste por una plataforma, la cancelación se tramita <strong>desde ella</strong>, con las condiciones que figuran en tu reserva.</p>",
                'en' => "<p>🗓️ The minimum stay is <strong>two nights</strong>. If you search for a single night no availability will show: it does not mean we are full.</p>\n"
                    . "<p>✅ Cancellation is <strong>free up to 30 days before</strong> arrival. After that, a penalty equal to <strong>the first night</strong> applies.</p>\n"
                    . "<p>💳 Prepaying the first night is what <strong>activates and secures</strong> these conditions. The balance is paid on arrival. 🔑</p>\n"
                    . "<p>🏨 If you booked through a platform, the cancellation is handled <strong>there</strong>, under the conditions stated in your reservation.</p>",
                'pt' => "<p>🗓️ A estadia mínima é de <strong>duas noites</strong>. Se procurar apenas uma noite não aparecerá disponibilidade: não significa que esteja ocupado.</p>\n"
                    . "<p>✅ O cancelamento é <strong>gratuito até 30 dias antes</strong> da chegada. A partir daí cobra-se uma penalidade equivalente à <strong>primeira noite</strong>.</p>\n"
                    . "<p>💳 O pré-pagamento da primeira noite é o que <strong>ativa e garante</strong> estas condições. O restante paga-se à chegada. 🔑</p>\n"
                    . "<p>🏨 Se reservou por uma plataforma, o cancelamento trata-se <strong>por ela</strong>, com as condições que constam da sua reserva.</p>",
                'fr' => "<p>🗓️ Le séjour minimum est de <strong>deux nuits</strong>. Si vous cherchez une seule nuit, aucune disponibilité n'apparaîtra : cela ne veut pas dire que tout est occupé.</p>\n"
                    . "<p>✅ L'annulation est <strong>gratuite jusqu'à 30 jours avant</strong> l'arrivée. Passé ce délai, une pénalité équivalente à <strong>la première nuit</strong> est appliquée.</p>\n"
                    . "<p>💳 Le prépaiement de la première nuit est ce qui <strong>active et garantit</strong> ces conditions. Le solde se règle à l'arrivée. 🔑</p>\n"
                    . "<p>🏨 Si vous avez réservé via une plateforme, l'annulation se fait <strong>depuis celle-ci</strong>, selon les conditions indiquées dans votre réservation.</p>",
                'de' => "<p>🗓️ Der Mindestaufenthalt beträgt <strong>zwei Nächte</strong>. Wenn Sie nur eine Nacht suchen, wird keine Verfügbarkeit angezeigt: Das heißt nicht, dass alles belegt ist.</p>\n"
                    . "<p>✅ Die Stornierung ist <strong>bis 30 Tage vor</strong> der Anreise <strong>kostenlos</strong>. Danach wird eine Gebühr in Höhe <strong>der ersten Nacht</strong> berechnet.</p>\n"
                    . "<p>💳 Die Vorauszahlung der ersten Nacht <strong>aktiviert und sichert</strong> diese Bedingungen. Der Restbetrag wird bei der Ankunft bezahlt. 🔑</p>\n"
                    . "<p>🏨 Wenn Sie über eine Plattform gebucht haben, wird die Stornierung <strong>dort</strong> abgewickelt, zu den in Ihrer Buchung genannten Bedingungen.</p>",
                'it' => "<p>🗓️ Il soggiorno minimo è di <strong>due notti</strong>. Se cerchi una sola notte non comparirà disponibilità: non significa che sia tutto occupato.</p>\n"
                    . "<p>✅ La cancellazione è <strong>gratuita fino a 30 giorni prima</strong> dell'arrivo. Dopo tale termine si applica una penale pari <strong>alla prima notte</strong>.</p>\n"
                    . "<p>💳 Il pagamento anticipato della prima notte è ciò che <strong>attiva e garantisce</strong> queste condizioni. Il saldo si paga all'arrivo. 🔑</p>\n"
                    . "<p>🏨 Se hai prenotato tramite una piattaforma, la cancellazione si gestisce <strong>da lì</strong>, alle condizioni indicate nella tua prenotazione.</p>",
                'nl' => "<p>🗓️ Het minimumverblijf is <strong>twee nachten</strong>. Zoekt u één nacht, dan verschijnt er geen beschikbaarheid: dat betekent niet dat alles bezet is.</p>\n"
                    . "<p>✅ Annuleren is <strong>gratis tot 30 dagen vóór</strong> aankomst. Daarna wordt een boete ter hoogte van <strong>de eerste nacht</strong> in rekening gebracht.</p>\n"
                    . "<p>💳 De vooruitbetaling van de eerste nacht <strong>activeert en waarborgt</strong> deze voorwaarden. Het restant betaalt u bij aankomst. 🔑</p>\n"
                    . "<p>🏨 Heeft u via een platform geboekt, dan verloopt de annulering <strong>via dat platform</strong>, onder de voorwaarden die in uw reservering staan.</p>",
            ],
        ],
    ];

    /** El ítem al que se le añade el párrafo del equipaje, ya enlazado a las siete secciones. */
    private const string ITEM_EQUIPAJE = 'Early check in Late Check Out';

    /** Marca de idempotencia del párrafo: no aparece en ningún otro sitio de la guía. */
    private const string MARCA_EQUIPAJE = '🎒';

    /** @var array<string, string> */
    private const array EQUIPAJE = [
        'es' => "<p>🎒 <strong>Guardamos tu equipaje</strong>, tanto si llegas antes de la hora de entrada como si ya hiciste el check-out y sigues en la ciudad.</p>\n"
            . "<p>📅 También podemos guardarlo <strong>varios días</strong> —por ejemplo mientras haces el Salkantay—: avísanos <strong>con un día de antelación</strong> para coordinarlo.</p>\n"
            . "<p>🧳 Cuando lo dejes, dinos <strong>cuántos bultos son</strong>. Puedes recogerlo antes de lo previsto sin problema.</p>",
        'en' => "<p>🎒 <strong>We store your luggage</strong>, whether you arrive before check-in time or you have already checked out and are still in the city.</p>\n"
            . "<p>📅 We can also keep it for <strong>several days</strong> — while you hike the Salkantay, for example: let us know <strong>one day in advance</strong> so we can arrange it.</p>\n"
            . "<p>🧳 When you drop it off, tell us <strong>how many pieces</strong> there are. You can pick it up earlier than planned, no problem.</p>",
        'pt' => "<p>🎒 <strong>Guardamos a sua bagagem</strong>, tanto se chegar antes da hora de entrada como se já fez o check-out e continua na cidade.</p>\n"
            . "<p>📅 Também a podemos guardar <strong>vários dias</strong> — por exemplo enquanto faz o Salkantay: avise-nos <strong>com um dia de antecedência</strong> para combinarmos.</p>\n"
            . "<p>🧳 Quando a deixar, diga-nos <strong>quantos volumes</strong> são. Pode levantá-la antes do previsto, sem problema.</p>",
        'fr' => "<p>🎒 <strong>Nous gardons vos bagages</strong>, que vous arriviez avant l'heure d'arrivée ou que vous ayez déjà fait le check-out et restiez en ville.</p>\n"
            . "<p>📅 Nous pouvons aussi les garder <strong>plusieurs jours</strong> — pendant le trek du Salkantay, par exemple : prévenez-nous <strong>un jour à l'avance</strong> pour que nous puissions nous organiser.</p>\n"
            . "<p>🧳 Au moment de les déposer, dites-nous <strong>combien de bagages</strong> il y a. Vous pouvez les récupérer plus tôt que prévu, sans problème.</p>",
        'de' => "<p>🎒 <strong>Wir bewahren Ihr Gepäck auf</strong>, ob Sie vor der Check-in-Zeit ankommen oder bereits ausgecheckt haben und noch in der Stadt sind.</p>\n"
            . "<p>📅 Wir können es auch <strong>mehrere Tage</strong> aufbewahren — zum Beispiel während des Salkantay-Treks: Sagen Sie uns <strong>einen Tag vorher</strong> Bescheid, damit wir es organisieren können.</p>\n"
            . "<p>🧳 Sagen Sie uns bei der Abgabe, <strong>wie viele Gepäckstücke</strong> es sind. Sie können es problemlos früher als geplant abholen.</p>",
        'it' => "<p>🎒 <strong>Custodiamo il tuo bagaglio</strong>, sia che arrivi prima dell'orario di ingresso, sia che tu abbia già fatto il check-out e resti in città.</p>\n"
            . "<p>📅 Possiamo tenerlo anche <strong>per più giorni</strong> — per esempio mentre fai il Salkantay: avvisaci <strong>con un giorno di anticipo</strong> per organizzarci.</p>\n"
            . "<p>🧳 Quando lo lasci, dicci <strong>quanti colli</strong> sono. Puoi ritirarlo prima del previsto, senza problemi.</p>",
        'nl' => "<p>🎒 <strong>Wij bewaren uw bagage</strong>, of u nu vóór de incheck-tijd aankomt of al hebt uitgecheckt en nog in de stad bent.</p>\n"
            . "<p>📅 We kunnen haar ook <strong>meerdere dagen</strong> bewaren — bijvoorbeeld tijdens de Salkantay-trek: laat het ons <strong>een dag van tevoren</strong> weten zodat we het kunnen regelen.</p>\n"
            . "<p>🧳 Zeg bij het afgeven <strong>hoeveel stuks</strong> het zijn. U kunt de bagage zonder probleem eerder ophalen dan gepland.</p>",
    ];

    /** Frase exacta que hoy cierra el bloque EQUIPAJE del campo del agente. */
    private const string AGENTE_ANTES = 'Ofrecelo con confianza. Si pregunta el costo, avisa al
equipo.';

    private const string AGENTE_DESPUES = 'Ofrecelo con confianza. Si pregunta el costo, avisa al
equipo.

Tambien se puede guardar varios dias, por ejemplo mientras hace un trek: que avise con un dia de
antelacion para coordinarlo, y que diga cuantos bultos son.';

    public function getDescription(): string
    {
        return 'Crea los ítems de traslados, estacionamiento, facturación y cancelación en las 7 guías, '
            . 'y publica el equipaje que sólo veía el agente.';
    }

    public function up(Schema $schema): void
    {
        $ids = [];

        foreach (self::ITEMS as $nombre => $item) {
            $ids[$nombre] = $this->crearSiFalta($nombre, $item);
        }

        foreach ($this->seccionesDestino() as $tipo => $secciones) {
            foreach ($secciones as $seccionId) {
                $this->colocarEn($seccionId, $tipo, $ids);
            }
        }

        $this->publicarEquipaje();
    }

    public function down(Schema $schema): void
    {
        $nombres = array_keys(self::ITEMS);
        $marcadores = implode(',', array_fill(0, count($nombres), '?'));

        // Primero los enlaces: la FK impide borrar el ítem mientras cuelgue de una sección. Los
        // huecos que deja en `orden` no molestan — es una clave de ordenación, no un índice.
        $this->addSql(
            "DELETE shi FROM pms_guia_seccion_has_item shi
               JOIN pms_guia_item i ON i.id = shi.item_id
              WHERE i.nombre_interno IN ($marcadores)",
            $nombres
        );

        $this->addSql(
            "DELETE FROM pms_guia_item WHERE nombre_interno IN ($marcadores)",
            $nombres
        );

        $this->quitarEquipaje();
    }

    /**
     * Inserta el ítem y devuelve su id binario. Si ya existe, devuelve el suyo y no lo toca.
     *
     * No sobrescribe a propósito: si alguien ya editó el texto en el panel, esta migración
     * corriendo en otro entorno no tiene por qué ganarle.
     *
     * @param array{icono: string, visibilidad: string, terminos: string, agente: string,
     *              titulo: array<string, string>, cuerpo: array<string, string>} $item
     */
    private function crearSiFalta(string $nombre, array $item): string
    {
        $existente = $this->connection->fetchOne(
            'SELECT id FROM pms_guia_item WHERE nombre_interno = :nombre',
            ['nombre' => $nombre]
        );

        if ($existente !== false) {
            return (string) $existente;
        }

        $id = Uuid::v7()->toBinary();

        $this->addSql(
            'INSERT INTO pms_guia_item
                (id, tipo, titulo, descripcion, nombre_interno, metadata, sobreescribir_traduccion,
                 icono, visibilidad, agente_terminos, agente_requiere_humano, agente_contenido, created_at)
             VALUES (:id, :tipo, :titulo, :descripcion, :nombre, :metadata, 0, :icono, :visibilidad,
                     :terminos, 0, :agente, NOW())',
            [
                'id' => $id,
                'tipo' => 'card',
                'titulo' => $this->i18n($item['titulo']),
                'descripcion' => $this->i18n($item['cuerpo']),
                'nombre' => $nombre,
                'metadata' => '[]',
                'icono' => $item['icono'],
                'visibilidad' => $item['visibilidad'],
                'terminos' => $item['terminos'],
                'agente' => $item['agente'],
            ]
        );

        return $id;
    }

    /**
     * Las secciones donde entra cada grupo, agrupadas por el valor de `seccion` de ITEMS.
     *
     * @return array<string, list<string>>
     */
    private function seccionesDestino(): array
    {
        return [
            'ingreso' => $this->connection->fetchFirstColumn(
                "SELECT DISTINCT s.id
                   FROM pms_guia_seccion s
                   JOIN pms_guia_has_seccion ghs ON ghs.seccion_id = s.id
                  WHERE s.nombre_interno LIKE 'Ingreso (casa%'"
            ),
            'pagos' => $this->connection->fetchFirstColumn(
                "SELECT DISTINCT s.id
                   FROM pms_guia_seccion s
                   JOIN pms_guia_has_seccion ghs ON ghs.seccion_id = s.id
                  WHERE s.nombre_interno = 'Pagos y Reglamento (general)'"
            ),
        ];
    }

    /**
     * Coloca en una sección los ítems de su grupo, cada uno detrás del que dice `tras`.
     *
     * El orden se calcula **entero en memoria** y luego se reescribe. Consultar la posición del
     * anterior con `fetchOne()` no valdría: `addSql()` difiere la ejecución, así que cuando le
     * toca el turno a «Estacionamiento» su predecesor «Traslados» todavía no está en la base y
     * la consulta devolvería `false`.
     *
     * @param array<string, string> $ids  nombre_interno → id binario
     */
    private function colocarEn(string $seccionId, string $tipo, array $ids): void
    {
        $filas = $this->connection->fetchAllAssociative(
            'SELECT i.nombre_interno AS nombre
               FROM pms_guia_seccion_has_item shi
               JOIN pms_guia_item i ON i.id = shi.item_id
              WHERE shi.seccion_id = :seccion
              ORDER BY shi.orden',
            ['seccion' => $seccionId]
        );

        $orden = array_column($filas, 'nombre');
        $nuevos = [];

        foreach (self::ITEMS as $nombre => $item) {
            if ($item['seccion'] !== $tipo || in_array($nombre, $orden, true)) {
                continue;
            }

            $posicion = array_search($item['tras'], $orden, true);

            // Sin el ítem de referencia, al final: es preferible que salga en un sitio raro a
            // que no salga. No pasa hoy en ninguna de las secciones.
            $orden = $posicion === false
                ? [...$orden, $nombre]
                : array_merge(
                    array_slice($orden, 0, $posicion + 1),
                    [$nombre],
                    array_slice($orden, $posicion + 1)
                );

            $nuevos[] = $nombre;
        }

        if ($nuevos === []) {
            return;
        }

        foreach ($orden as $posicion => $nombre) {
            if (in_array($nombre, $nuevos, true)) {
                $this->addSql(
                    'INSERT INTO pms_guia_seccion_has_item (id, seccion_id, item_id, orden, activo, created_at)
                     VALUES (:id, :seccion, :item, :orden, 1, NOW())',
                    [
                        'id' => Uuid::v7()->toBinary(),
                        'seccion' => $seccionId,
                        'item' => $ids[$nombre],
                        'orden' => $posicion,
                    ]
                );

                continue;
            }

            $this->addSql(
                'UPDATE pms_guia_seccion_has_item shi
                   JOIN pms_guia_item i ON i.id = shi.item_id
                    SET shi.orden = :orden
                  WHERE shi.seccion_id = :seccion AND i.nombre_interno = :nombre',
                ['orden' => $posicion, 'seccion' => $seccionId, 'nombre' => $nombre]
            );
        }
    }

    /**
     * Saca el equipaje del campo del agente al cuerpo que lee el huésped.
     *
     * El servicio se presta desde siempre y el agente lo ofrece, pero **el ítem publicado no lo
     * mencionaba en ningún idioma**: quien abría la guía buscando dónde dejar la mochila no
     * encontraba nada y acababa preguntando por el chat. 56 veces en lo que va de año.
     */
    private function publicarEquipaje(): void
    {
        $fila = $this->connection->fetchAssociative(
            'SELECT id, descripcion, agente_contenido FROM pms_guia_item WHERE nombre_interno = :nombre',
            ['nombre' => self::ITEM_EQUIPAJE]
        );

        if ($fila === false) {
            $this->write('  No existe «' . self::ITEM_EQUIPAJE . '»; no hay dónde publicar el equipaje.');

            return;
        }

        $bloques = json_decode((string) $fila['descripcion'], true);

        if (is_array($bloques)) {
            $tocado = false;

            foreach ($bloques as $i => $bloque) {
                $idioma = is_array($bloque) ? (string) ($bloque['language'] ?? '') : '';
                $html = is_array($bloque) ? (string) ($bloque['content'] ?? '') : '';

                if (!isset(self::EQUIPAJE[$idioma]) || str_contains($html, self::MARCA_EQUIPAJE)) {
                    continue;
                }

                $bloques[$i]['content'] = rtrim($html) . "\n" . self::EQUIPAJE[$idioma];
                $tocado = true;
            }

            if ($tocado) {
                $this->addSql(
                    'UPDATE pms_guia_item SET descripcion = :descripcion WHERE id = :id',
                    [
                        'descripcion' => json_encode($bloques, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                        'id' => $fila['id'],
                    ]
                );
            }
        }

        // Y en el campo del agente, las dos condiciones que sólo estaban en la cabeza del equipo:
        // avisar un día antes para varios días, y decir cuántos bultos son.
        $agente = (string) $fila['agente_contenido'];

        if ($agente !== '' && str_contains($agente, self::AGENTE_ANTES)) {
            $this->addSql(
                'UPDATE pms_guia_item SET agente_contenido = :texto WHERE id = :id',
                [
                    'texto' => str_replace(self::AGENTE_ANTES, self::AGENTE_DESPUES, $agente),
                    'id' => $fila['id'],
                ]
            );
        }
    }

    private function quitarEquipaje(): void
    {
        $fila = $this->connection->fetchAssociative(
            'SELECT id, descripcion, agente_contenido FROM pms_guia_item WHERE nombre_interno = :nombre',
            ['nombre' => self::ITEM_EQUIPAJE]
        );

        if ($fila === false) {
            return;
        }

        $bloques = json_decode((string) $fila['descripcion'], true);

        if (is_array($bloques)) {
            foreach ($bloques as $i => $bloque) {
                $idioma = is_array($bloque) ? (string) ($bloque['language'] ?? '') : '';

                if (!isset(self::EQUIPAJE[$idioma])) {
                    continue;
                }

                $bloques[$i]['content'] = rtrim(str_replace(
                    self::EQUIPAJE[$idioma],
                    '',
                    (string) ($bloque['content'] ?? '')
                ));
            }

            $this->addSql(
                'UPDATE pms_guia_item SET descripcion = :descripcion WHERE id = :id',
                [
                    'descripcion' => json_encode($bloques, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                    'id' => $fila['id'],
                ]
            );
        }

        $agente = (string) $fila['agente_contenido'];

        if ($agente !== '') {
            $this->addSql(
                'UPDATE pms_guia_item SET agente_contenido = :texto WHERE id = :id',
                [
                    'texto' => str_replace(self::AGENTE_DESPUES, self::AGENTE_ANTES, $agente),
                    'id' => $fila['id'],
                ]
            );
        }
    }

    /**
     * Al formato i18n del proyecto: `[{"language": "es", "content": "..."}]`.
     *
     * @param array<string, string> $porIdioma
     */
    private function i18n(array $porIdioma): string
    {
        $bloques = [];

        foreach ($porIdioma as $idioma => $contenido) {
            $bloques[] = ['language' => $idioma, 'content' => $contenido];
        }

        return json_encode($bloques, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
