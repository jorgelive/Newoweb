<?php

declare(strict_types=1);

namespace App\Operacion\Service;

use App\Operacion\Entity\OperacionOrdenServicio;
use App\Operacion\Entity\OperacionOrdenServicioItem;
use App\Travel\Entity\TravelOrganizacion;
use App\Cotizacion\Entity\CotizacionFile;
use App\Message\Service\Conversacion\ContactoDelAsunto;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Uid\Uuid;

/**
 * El texto que se le manda al proveedor: **qué operar**, no cuánto cuesta.
 *
 * ── Por qué no lleva importes ───────────────────────────────────────────────
 * Es la regla que ya estaba escrita en `OperacionOrdenServicio::$totalOs`: «al proveedor no se
 * le manda un total». Aquí se extiende a las líneas, y por el mismo motivo: este documento es
 * una **solicitud de servicio** —a quién recoger, dónde, a qué hora y cuántos son— y el dinero
 * ya está pactado por otro canal. Meterlo abre una negociación en el peor momento, cuando lo
 * que hace falta es que la plaza exista.
 *
 * Lo que se paga se lleva aparte: {@see \App\Operacion\Entity\OperacionPago}.
 *
 * ── Sale de los ITEMS, no de los servicios ──────────────────────────────────
 * Los ítems son la copia **congelada** al emitir. Componer el documento desde los servicios
 * vivos haría que reenviarlo un mes después mandara algo distinto de lo que el proveedor tiene
 * en la mano, sin que nada lo dijera — y ése es justo el escenario en que se reenvía.
 *
 * ⚠️ Una orden en borrador no tiene ítems: el documento sale vacío, y por eso enviar sólo se
 * ofrece a partir de emitida.
 */
final readonly class OperacionOrdenDocumento
{
    public function __construct(
        private EntityManagerInterface $em,
        private ContactoDelAsunto $contacto,
        #[Autowire(param: 'operaciones_telefono_emergencia')]
        private string $telefonoEmergencia = '',
    )
    {
    }

    /**
     * «Nune & Todd · Todd Nune · 1 habitación · 2 pax · 999 888 777» — quién viaja.
     *
     * ⚠️ Con UN expediente esto se sobreentiende para toda la orden y las líneas no se etiquetan;
     * con dos o más, cada línea lleva su grupo y este bloque hace de directorio del documento.
     *
     * @param list<array{localizador: string, grupo: string, pasajero: string, telefono: string, habitaciones: int, pax: int, dias: int, noches: int}> $grupos
     *
     * @return list<string>
     */
    private function bloqueDeGrupos(array $grupos): array
    {
        $lineas = [];

        foreach ($grupos as $g) {
            // ⚠️ Una sola resolución por grupo. Escrito dos veces —en la condición y en el
            // `sprintf`— cada grupo costaba el doble: dos búsquedas del expediente y dos del hilo,
            // con su consulta de semillas cuando el hilo no tiene teléfono vivo.
            $telefono = $this->telefonoVivo($g);

            $partes = array_values(array_filter([
                $g['grupo'] !== '' ? sprintf('*%s*', $g['grupo']) : null,
                $g['pasajero'] !== '' && $g['pasajero'] !== $g['grupo'] ? $g['pasajero'] : null,
                $g['habitaciones'] > 0
                    ? sprintf('%d %s', $g['habitaciones'], $g['habitaciones'] === 1 ? 'habitación' : 'habitaciones')
                    : null,
                $g['pax'] > 0 ? sprintf('%d pax', $g['pax']) : null,
                $g['dias'] > 0
                    ? trim(sprintf(
                        '%d %s%s',
                        $g['dias'],
                        $g['dias'] === 1 ? 'día' : 'días',
                        $g['noches'] > 0 ? sprintf(', %d %s', $g['noches'], $g['noches'] === 1 ? 'noche' : 'noches') : ''
                    ))
                    : null,
                $telefono !== '' ? sprintf('tel. %s', $telefono) : null,
            ], static fn (?string $p): bool => $p !== null));

            if ($partes !== []) {
                $lineas[] = '👥 ' . implode('  ·  ', $partes);
            }
        }

        return $lineas;
    }

    /**
     * El teléfono se resuelve **AHORA**, no se lee del congelado.
     *
     * 🔥 **Un teléfono no es un término del acuerdo: es cómo se llama a alguien.** El resto del
     * bloque —localizador, pax, días, noches— se congela al emitir porque describe lo que se
     * encargó, y cambiarlo después sería reescribir la historia. Un número de contacto es lo
     * contrario: si cambia, el proveedor tiene que recibir el NUEVO. Congelarlo garantiza mandar
     * un número que ya no contesta, que es justo lo que no sirve.
     *
     * ⚠️ **Y la semilla del expediente tampoco es la verdad.** `CotizacionFile::$telefono` es el
     * dato con el que se sembró la identidad de esa persona; a partir de ahí manda la IDENTIDAD,
     * que es donde se corrige, se retira y se veta ({@see ContactoDelAsunto}). Es exactamente lo
     * que ya explica `ContactoDeIdentidad.vue`: un dato que se puede editar y no se usa es peor
     * que uno que no se puede editar.
     *
     * Pasó el 08/09/2026: se cambió el teléfono en identidad y la orden seguía mandando el de
     * prueba, porque `congelarGrupos()` había copiado la semilla al emitir.
     *
     * ⚠️ Si la identidad no da nada, se cae a lo congelado: un número viejo es más útil que
     * ninguno, y quedarse callado obligaría a buscarlo en otra pantalla.
     *
     * @param array{localizador: string, telefono: string} $grupo
     */
    private function telefonoVivo(array $grupo): string
    {
        $file = $grupo['localizador'] === ''
            ? null
            : $this->em->getRepository(CotizacionFile::class)->findOneBy(['localizador' => $grupo['localizador']]);

        if ($file === null) {
            return $grupo['telefono'];
        }

        $vivo = $this->contacto->para('cotizacion_file', (string) $file->getId())['telefono'] ?? null;

        return $vivo !== null && $vivo !== '' ? $vivo : $grupo['telefono'];
    }

    /**
     * @param string|null $enlace El enlace público de la orden, si ya la tiene.
     *
     * @return array{asunto: string, cuerpo: string, lineas: int}
     */
    public function para(OperacionOrdenServicio $orden, ?string $enlace = null): array
    {
        // Qué ítems enseñan el recojo: uno al día, salvo que cambie — lo decide la orden, que es
        // quien ve todas sus líneas. Ver `OperacionOrdenServicio::getRutasVisibles()`.
        $rutas = $orden->getRutasVisibles();

        // ⚠️ ORDENADOS, y por la misma vía que `getRutasVisibles()`. Iterando la colección cruda
        // se imprimía en el orden en que se marcaron las filas: las fechas salían a saltos y —peor—
        // la regla del recojo, que sí mira la lista ordenada, dejaba sin «Recoge en…» a líneas que
        // debían llevarlo. Dos listas distintas para el mismo documento no pueden coincidir.
        //
        // ── AGRUPADO POR DÍA ────────────────────────────────────────────────
        // Antes cada línea repetía su fecha y todas iban seguidas: un bloque de cinco renglones
        // idénticos en el que hay que leerlo entero para saber cuántos días son. El proveedor
        // organiza por jornada —«el miércoles tengo tres»—, así que el día encabeza y las suyas
        // van debajo. Es la misma información con la mitad de esfuerzo.
        $porDia = [];
        $bloques = 0;

        foreach ($orden->getItemsOrdenados() as $item) {
            $clave = $item->getFechaServicio()?->format('Y-m-d') ?? '';
            $porDia[$clave]['etiqueta'] ??= $item->getEtiquetaDia();
            $porDia[$clave]['lineas'][] = $this->linea($item, $rutas);
            ++$bloques;
        }

        $partesCuerpo = [];

        foreach ($porDia as $dia) {
            // Los asteriscos son la negrita de WhatsApp. En un correo de texto plano se ven como
            // asteriscos —feo pero legible—; el formato de verdad va en la página y el PDF.
            $partesCuerpo[] = sprintf("*%s*\n%s", $dia['etiqueta'], implode("\n", $dia['lineas']));
        }

        // ── EL SALUDO ───────────────────────────────────────────────────────
        //
        // Un mensaje que abre con «*Orden de Servicio OS-…*» se lee como un volcado de sistema.
        // Al otro lado hay una persona y esto es una petición de trabajo, no un ticket.
        //
        // Con la RAZÓN SOCIAL y no el nombre comercial: es como se llama la empresa en lo que se
        // firma y se factura, y es lo que espera ver quien recibe un encargo formal. Si no la
        // tiene —o el destinatario no está en el catálogo—, cae al nombre con el que se le conoce
        // antes que a un saludo genérico.
        // ⚠️ Quién viaja va ARRIBA, antes de las líneas: al proveedor le llegaban horas y pax sin
        // decirle de quién era el encargo ni a quién llamar. Con un solo grupo se sobreentendía;
        // con dos, no había forma de saber qué línea era de quién.
        $grupos = $this->bloqueDeGrupos($orden->getGruposSnapshot());

        $partes = array_filter([
            sprintf('Estimado equipo de %s:', $this->tratamientoDelDestinatario($orden)),
            sprintf('*Orden de Servicio %s*', $orden->getNumeroOs()),
            $grupos === [] ? null : implode("\n", $grupos),
            $partesCuerpo === []
                ? '(sin líneas: la orden todavía no se ha emitido)'
                : implode("\n\n", $partesCuerpo),
            'Por favor confirmar recepción y disponibilidad.',
            // ── EL ENLACE, PRESENTADO ───────────────────────────────────────
            //
            // Una URL suelta al final de un mensaje se lee como una firma automática y no se
            // pulsa. Con una línea que diga qué hay al otro lado, se pulsa.
            //
            // ⚠️ Va DENTRO del cuerpo y no lo pega quien envía. Antes lo concatenaba
            // `OperacionOrdenEnvio::enviar()` y el botón «Copiar» del front lo repetía por su
            // cuenta: dos sitios componiendo el mismo texto, y el del grupo podía recibir una
            // versión distinta del que lo recibe por chat. Ahora se compone una vez, aquí.
            $enlace === null ? null : "Puede consultar la orden de servicio en el siguiente enlace:\n" . $enlace,
            // ── EL TELÉFONO DE EMERGENCIA, AL PIE ───────────────────────────
            //
            // Lo que un conductor necesita a las 22:00 cuando el vuelo se retrasa. No existía en
            // ninguna parte del sistema: lo único parecido era el WhatsApp del establecimiento,
            // que es del PMS, por propiedad, y un proveedor de transporte no sabe cuál es.
            //
            // ⚠️ Vacío = no sale. Un número inventado en un documento que se manda fuera es peor
            // que no ponerlo: se llama y no contesta nadie, justo el día que hacía falta.
            trim($this->telefonoEmergencia) === ''
                ? null
                : sprintf('Ante cualquier urgencia durante el servicio: %s', trim($this->telefonoEmergencia)),
            // El único que puede faltar es el enlace: un borrador todavía no tiene llave pública.
        ], static fn (?string $p): bool => $p !== null);

        $cuerpo = implode("\n\n", $partes);


        return [
            'asunto' => sprintf('Orden de Servicio %s', $orden->getNumeroOs()),
            'cuerpo' => $cuerpo,
            'lineas' => $bloques,
        ];
    }

    /**
     * Cómo se le llama al destinatario en el saludo.
     *
     * Cascada corta: **razón social del catálogo → nombre congelado en la orden**. La primera es
     * como se llama la empresa en lo que se firma; la segunda es con la que la conoce el equipo,
     * y sirve igual para saludar.
     *
     * ⚠️ Se lee del catálogo EN VIVO y no del snapshot: si la empresa cambió de razón social, lo
     * correcto es saludarla como se llama hoy. Lo que sí queda congelado es el contenido de la
     * orden, que es lo que se pactó — el saludo no es parte del pacto.
     */
    private function tratamientoDelDestinatario(OperacionOrdenServicio $orden): string
    {
        $id = $orden->getCompradorMaestroId();

        if ($id !== null && Uuid::isValid($id)) {
            $organizacion = $this->em->find(TravelOrganizacion::class, Uuid::fromString($id));

            if ($organizacion instanceof TravelOrganizacion) {
                $razon = trim((string) $organizacion->getRazonSocial());

                if ($razon !== '') {
                    return $razon;
                }
            }
        }

        return trim((string) $orden->getCompradorNombre()) ?: 'nuestro proveedor';
    }

    /**
     * Una línea del documento.
     *
     * El orden es el de quien lo va a operar: primero CUÁNDO, luego QUÉ, y al final los datos
     * que necesita para presentarse —hora de recojo y cuántos son—. La descripción va después
     * de la fecha a propósito: el proveedor busca por día, no por nombre de servicio.
     */
    /** @param array<string, string> $rutas id de ítem → línea de recojo, si le toca enseñarla */
    private function linea(OperacionOrdenServicioItem $item, array $rutas): string
    {
        // ⚠️ La fecha YA NO va en la línea: la lleva el encabezado del día. Repetirla en cada
        // renglón era la mitad del ancho gastado en un dato que no cambia dentro del bloque.
        $partes = [];

        $hora = trim((string) $item->getHora());

        if ($hora !== '') {
            $partes[] = $hora;
        }

        // QUÉ hay que hacer, en negrita, y la variante de tarifa detrás entre paréntesis.
        //
        // ⚠️ Antes aquí iba `getDescripcion()` a secas, que es SÓLO la variante: al que hacía el
        // traslado Ollantaytambo→Cusco le llegaba una línea que decía «Auto», y al hotelero
        // «Hotel 4 estrellas por grupo». La variante importa —distingue el auto de la van— pero
        // como calificador de un encargo, no como el encargo.
        $partes[] = sprintf('*%s*', $item->getTituloParaProveedor());

        if (($variante = $item->getVarianteParaProveedor()) !== null) {
            $partes[] = $variante;
        }

        // QUÉ exactamente se le contrata: la habitación, la clase de tren. Va después de la
        // variante porque la concreta —«Alojamiento en Cusco · Hotel 4 estrellas · Habitación
        // premium»— y es el dato con el que el hotelero busca la reserva.
        //
        // Se calla si repite lo que ya se dijo: cuando el componente tiene servicio de prestador,
        // `resolverDescripcion()` lo usa como descripción, así que variante y servicio coinciden.
        $servicio = trim((string) $item->getPrestadorServicioNombre());

        if ($servicio !== '' && $servicio !== $variante && $servicio !== $item->getTituloParaProveedor()) {
            $partes[] = $servicio;
        }

        // La hora de recojo CONFIRMADA es la que vale; si no la hay todavía, no se inventa.
        //
        // ⚠️ Y sólo se dice cuando DIFIERE de la hora del servicio. En los datos reales coinciden
        // casi siempre —«04:00 · Tacama · recojo 04:00»— y repetir el mismo dato dos veces por
        // línea enseña a no leerlo, que es exactamente lo contrario de lo que hace falta el día
        // que sí sean distintas.
        if (($recojo = trim((string) $item->getHoraRecojoConfirmada())) !== '' && $recojo !== $hora) {
            $partes[] = sprintf('recojo %s', $recojo);
        }

        if (($pax = $item->getCantidadPax()) !== null && $pax > 0) {
            $partes[] = sprintf('%d pax', $pax);
        }

        // ⚠️ **Cuánto y hasta cuándo**, que es lo que faltaba. Al hotelero le llegaba «Lun 31 ago
        // · Habitación Superior · 2 pax» — la entrada, sin salida y sin número de noches: el
        // encargo sin su duración, y con la parte que más se pregunta por teléfono.
        //
        // Las dos redacciones viven en el ÍTEM, que es quien las pinta también en la página
        // pública: escritas aquí también, cambiarían en un sitio y no en el otro.
        if (($cuanto = $item->getCantidadParaProveedor()) !== null && $item->getSustantivoUnidad() !== null) {
            $partes[] = $cuanto;
        }

        if (($hasta = $item->getHastaParaProveedor()) !== null) {
            $partes[] = $hasta;
        }

        // ── Dónde recoge y dónde deja ───────────────────────────────────────
        //
        // Va en su propio renglón: metida en la ristra de la línea, entre la hora y los pax, una
        // dirección de cuarenta caracteres sepulta todo lo demás. La redacción la compone el
        // ítem, que es también quien la pinta en la página pública — ver `rutaParaLaOrden()`.
        $ruta = $rutas[$item->getId()?->toRfc4122() ?? ''] ?? null;

        // El prestador va sólo cuando NO es el destinatario: si coinciden, decírselo es ruido.
        $prestador = trim((string) $item->getPrestadorNombre());
        $comprador = trim((string) $item->getOrden()?->getCompradorNombre());

        if ($prestador !== '' && $prestador !== $comprador) {
            $partes[] = sprintf('opera %s', $prestador);
        }

        // El reloj marca dónde empieza cada servicio, que es lo que se busca al repasar el día.
        // Un icono y no un guion porque en una lista de cinco el ojo salta a la forma, no al signo.
        // El día del itinerario, sin negrita y al final: sitúa el servicio sin competir con él.
        //
        // La decisión de callarlo vive en la ENTIDAD (`getDiaParaProveedor()`), no aquí: antes se
        // comparaba sólo contra el título y el twig hacía lo mismo por su cuenta, así que un día
        // igual al componente salía duplicado. Una regla en un sitio, tres superficies que la
        // consumen.
        if (($dia = $item->getDiaParaProveedor()) !== null) {
            $partes[] = $dia;
        }

        // De quién es la línea. **Sólo con más de un grupo**: con uno, el encabezado ya lo dijo y
        // repetirlo en cada renglón es ruido.
        if ($item->getOrden()?->isMultigrupo() === true && ($grupo = trim((string) $item->getNombreGrupo())) !== '') {
            $partes[] = $grupo;
        }

        $linea = '🕐 ' . implode('  ·  ', $partes);

        // El pin va en su propio renglón, alineado bajo el reloj: es una dirección larga y metida
        // en la ristra sepulta la hora y los pax. Ver el comentario de arriba.
        if ($ruta !== null) {
            $linea .= "\n📍 " . $ruta;
        }

        // ── LO QUE HAY QUE SABER PARA OPERARLO ──────────────────────────────
        //
        // ⚠️ **Faltaba entero.** Aquí vive «Delta LATAM LA-2695 Aterriza 22:00», que es el dato
        // con el que un chófer decide a qué hora sale de casa. Estaba en La Biblia y en la página
        // pública, pero NO en el mensaje — o sea que por WhatsApp o correo, que es por donde el
        // proveedor lo recibe de verdad, se le pedía recoger en un aeropuerto sin decirle el
        // vuelo. Una línea por nota, porque son frases y encadenadas no se leen.
        foreach ($item->getNotasPrestador() as $nota) {
            $linea .= "\n📝 " . $nota;
        }

        return $linea;
    }
}
