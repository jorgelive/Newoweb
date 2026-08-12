<?php

declare(strict_types=1);

namespace App\Agent\Skill\Pms;

use App\Agent\Access\ActorInterface;
use App\Agent\Access\NivelRiesgo;
use App\Agent\Skill\SkillDefinition;
use App\Agent\Skill\SkillInterface;
use App\Agent\Skill\SkillParameter;
use App\Agent\Skill\SkillResult;
use App\Pms\Entity\PmsEventoCalendario;
use App\Pms\Entity\PmsGuiaItem;
use App\Pms\Entity\PmsGuiaSeccion;
use App\Pms\Entity\PmsReserva;
use App\Pms\Entity\PmsUnidad;
use App\Pms\Guia\PmsGuiaAcceso;
use App\Pms\Guia\PmsGuiaMensajes;
use App\Pms\Guia\PmsGuiaArbolFiltro;
use App\Pms\Guia\PmsGuiaContexto;
use App\Pms\Guia\PmsGuiaEstanciaResolver;
use App\Security\Roles;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\String\UnicodeString;
use Symfony\Component\Uid\Uuid;

/**
 * Responde con el contenido de la guía de la casita: cómo va la ducha, la calefacción, las
 * normas, cómo se entra.
 *
 * El WiFi NO sale de aquí: es una credencial que vive en `PmsUnidad::$wifiNetworks` y tiene su
 * propia skill ({@see ConsultarWifiSkill}). En el cuerpo de la guía sólo hay un `{{ wifi_data }}`
 * que el navegador convierte en widget.
 *
 * Antes de esto el agente sólo sabía dar `guide_url` —«mira tu guía»—, que es exactamente lo que
 * el huésped ya tenía y no le apetece leer a las 23:00 con el agua fría. La información estaba
 * escrita y publicada; simplemente no había forma de citarla en el chat.
 *
 * ### 🔒 No reimplementa la poda: la reutiliza
 *
 * La guía tiene contenido con candado —códigos de puerta, contraseñas del WiFi— y una matriz de
 * acceso por estado de la estancia ({@see PmsGuiaAcceso::permite()}). Esta skill pasa por el
 * MISMO camino que el endpoint público ({@see \App\Api\Provider\Pms\PmsGuiaHuespedProvider}):
 * `PmsGuiaAcceso::paraEvento()` + `PmsGuiaArbolFiltro::podar()`.
 *
 * Leer `PmsGuiaItem` directamente habría sido más corto y habría abierto dos agujeros a la vez:
 * los `{{ door_code }}` saldrían sin resolver (o peor, resueltos sin comprobar el acceso), y el
 * contenido `solo-ventana` se serviría fuera de su ventana. La regla vive en un sitio y aquí se
 * consume.
 *
 * ### Por qué devuelve texto y no HTML
 *
 * `PmsGuiaItem::$descripcion` es HTML editado por el operador. Al modelo se le da texto plano:
 * las etiquetas se pagan en tokens en cada consulta y no aportan nada a una respuesta hablada.
 */
final readonly class ConsultarGuiaSkill implements SkillInterface
{
    /** Cuántos ítems se devuelven con cuerpo. Más que esto es una guía entera en el chat. */
    private const int MAX_ITEMS = 4;

    /**
     * Recorte del cuerpo de cada ítem.
     *
     * Estuvo en 900, calibrado cuando lo único que llegaba aquí era el cuerpo publicado **sin
     * curar**: HTML de hasta 2.500 caracteres escrito para leerse en una pantalla, donde
     * cortar era defenderse de una parrafada que nadie había revisado.
     *
     * Ya no es ese el material. Con `agente_contenido` ({@see PmsGuiaItem::$agenteContenido})
     * el texto lo escribió alguien PARA el agente, y entonces **recortar es cortar una
     * decisión**. Medido sobre los 40 ítems: media 792, máximo 1.668; con 900 se mutilaban 10,
     * y entre ellos «Reglas», que perdía justo el final — la parte de que los huéspedes
     * adicionales se pagan.
     *
     * 1800 no corta ninguno hoy, ni en el cuerpo publicado ni en el campo del agente. Y es un
     * TOPE, no una reserva: con media de 792 no cambia lo que se paga en la llamada normal.
     * Lo que sí acota el gasto es {@see self::MAX_ITEMS}, que es lo que multiplica.
     */
    private const int MAX_CARACTERES = 1800;

    /**
     * A partir de aquí, lo que llega no es un tema: es el mensaje del huésped pegado entero.
     *
     * Es el fallo más peligroso de esta skill y va **al revés de lo que uno espera**: cuantas
     * más palabras trae la búsqueda, más fácil es que alguna case por casualidad. Con el
     * mensaje real
     *
     *   «la ducha del alojamiento donde estaba no funcionaba bien pero ésta está perfecta,
     *    me bañé en Aguas Calientes, estaba sucio»
     *
     * saltaba «Uso de la ducha» y el agente le explicaba a alguien que NO preguntó nada cómo
     * abrir el grifo. Encima «Aguas Calientes» es el pueblo de Machu Picchu, no el agua de la
     * casa: en este negocio ese topónimo aparece a diario.
     *
     * Ocho palabras dejan pasar de sobra las preguntas de verdad —«no sale agua caliente» son
     * cuatro, «a qué hora es el check out» seis— y cortan los relatos. Quien decide de qué se
     * habla es el MODELO, que tiene la conversación entera; esta skill sólo busca lo que le
     * digan que busque.
     */
    private const int MAX_PALABRAS_BUSQUEDA = 8;

    public function __construct(
        private EntityManagerInterface $em,
        private PmsGuiaArbolFiltro $filtro,
        private PmsGuiaEstanciaResolver $estancias,
    ) {}

    public function nombre(): string
    {
        return 'consultar_guia';
    }

    public function definicion(): SkillDefinition
    {
        return new SkillDefinition(
            descripcion: 'La GUÍA de la casita: cómo funciona la ducha o el agua caliente, '
                . 'la calefacción, cómo se entra, las normas, la limpieza. Es el texto real de '
                . 'ESA casita, que no es igual en todas, así que úsala antes de responder de '
                . 'memoria y antes de mandar el enlace de la guía. Para el WiFi usa '
                . 'consultar_wifi, no ésta. FUNCIONA EN DOS PASOS Y DECIDES TÚ: primero '
                . 'llámame SIN parámetros y te doy el «catalogo» —qué temas tiene esta casita y '
                . 'con qué palabras se pregunta cada uno—; lee lo que te dijo el huésped, mira '
                . 'qué tema responde a lo que quiere y vuelve a llamarme con ese «tema_id» para '
                . 'leer las instrucciones. ANTES DE NADA PREGÚNTATE SI HAY UNA PREGUNTA: si el '
                . 'huésped sólo está contando algo o quejándose («la ducha del hotel anterior '
                . 'no iba, ésta perfecta, me bañé en Aguas Calientes»), NO uses esta skill —ahí '
                . 'no pregunta por la ducha, y Aguas Calientes es un pueblo, no el agua de la '
                . 'casa—: contéstale tú. Si el tema está clarísimo puedes atajar pasando '
                . '«busqueda» con UNA O DOS PALABRAS («ducha», «basura»), nunca el mensaje '
                . 'entero. Si un tema sale «bloqueado», NO te lo inventes: di lo que dice su '
                . 'motivo. Y si en el catálogo no hay nada que encaje, dilo y pasa el enlace de '
                . 'la guía en vez de improvisar.',
            parametros: [
                SkillParameter::texto('tema_id', 'El id del tema que has elegido del '
                    . '«catalogo». Es la forma buena de pedir contenido: ya has decidido tú '
                    . 'cuál sirve.', requerido: false),
                SkillParameter::texto('busqueda', 'Atajo para cuando el tema es evidente: UNA o '
                    . 'DOS palabras («ducha», «basura»). Nunca el mensaje del huésped. Si dudas, '
                    . 'no lo uses: pide el catálogo y elige.', requerido: false),
                SkillParameter::texto('reserva_id', 'Sólo para el equipo, cuando consulta la guía '
                    . 'de OTRO huésped. Al hablar con el propio huésped no hace falta: se usa su '
                    . 'reserva.', requerido: false),
                SkillParameter::texto('casita', 'Nombre o slug de la casita, si la reserva tiene '
                    . 'varias y ya sabes de cuál preguntan.', requerido: false),
            ],
        );
    }

    /** El huésped la tiene por serlo; el equipo, por poder ver reservas. */
    public function rolesRequeridos(): array
    {
        // El prospecto entra, pero por la puerta pública: sin reserva sólo alcanza los ítems
        // marcados `publico`, que es lo que se puede contar antes de vender nada.
        return [Roles::PROSPECTO, Roles::HUESPED, Roles::RESERVAS_SHOW];
    }

    public function nivelRiesgo(): NivelRiesgo
    {
        return NivelRiesgo::Lectura;
    }

    public function ejecutar(array $entrada, ActorInterface $actor): SkillResult
    {
        // 🔒 El contexto MANDA sobre el parámetro. Si quien pregunta está atado a una reserva
        // —un huésped por chat—, es la suya y sólo la suya: aceptar aquí un `reserva_id` sería
        // regalarle la guía de cualquiera, con sus códigos de puerta, escribiendo un UUID.
        $reservaId = $actor->contextoId() ?? trim((string) ($entrada['reserva_id'] ?? ''));

        // 🚪 SEGUNDA PUERTA: por casita y sin reserva ninguna.
        //
        // La guía cuelga de la UNIDAD, pero hasta ahora sólo se llegaba a ella atravesando una
        // reserva, así que «¿cuál es la acomodación de la Casita 3?» no tenía respuesta ni
        // para un prospecto ni para el equipo — y el dato estaba ahí, a un JOIN, marcado como
        // público.
        //
        // Lo que se ve por esta puerta lo decide `PmsGuiaAcceso::publico()`, que es el peldaño
        // más bajo de LA MATRIZ y el que el propio modelo documenta como «el visitante sin
        // estancia». No hay lista blanca que mantener aquí: qué es público lo marca el
        // operador ítem a ítem en el campo `visibilidad`, y lo aplica el mismo filtro que
        // protege la guía del huésped.
        if ($reservaId === '' && ($actor->esProspecto() || $actor->esDelEquipo())) {
            return $this->guiaPublica($entrada, trim((string) ($entrada['casita'] ?? '')));
        }

        if ($actor->contextoId() === null && !$actor->esDelEquipo()) {
            return SkillResult::error('Esta conversación no está asociada a ninguna reserva.');
        }

        if (!Uuid::isValid($reservaId)) {
            return SkillResult::error(
                'No sé de qué reserva es la guía. Identifícala primero con buscar_reserva.'
            );
        }

        $reserva = $this->em->getRepository(PmsReserva::class)->find($reservaId);
        if ($reserva === null) {
            return SkillResult::error('No existe ninguna reserva con ese identificador.');
        }

        // Mismo filtro que el endpoint público: descarta estancias canceladas y las excluidas
        // a mano de la guía, y las devuelve en orden cronológico.
        $eventos = $reserva->getEventosActivosGuia();
        if ($eventos === []) {
            return SkillResult::error('Esta reserva no tiene ninguna estancia con guía publicada.');
        }

        // 🏠 ¿De qué casita? La guía NO es igual en cada una —la ducha de la 1 se abre con la
        // manija izquierda y la de la 2 con la derecha—, así que responder por la que no es da
        // la instrucción contraria. Ver PmsGuiaEstanciaResolver.
        $eleccion = $this->estancias->resolver($eventos, trim((string) ($entrada['casita'] ?? '')));

        if ($eleccion['ambiguo']) {
            return SkillResult::ok([
                'huesped' => $reserva->getNombreApellido(),
                'falta_datos' => ['casita'],
                'casitas' => $this->nombresDe($eleccion['candidatas']),
                'pregunta' => 'Esta reserva tiene varias casitas a la vez y la guía es distinta '
                    . 'en cada una (los códigos y hasta el grifo del agua caliente cambian). '
                    . 'Pregúntale al huésped en cuál está y vuelve a llamarme con «casita».',
            ]);
        }

        $evento = $eleccion['evento'];

        if ($evento === null) {
            return SkillResult::error(sprintf(
                'Esa casita no es de esta reserva. Las suyas son: %s.',
                implode(', ', $this->nombresDe($eleccion['candidatas']))
            ));
        }

        $unidad = $evento->getPmsUnidad();
        $guia = $unidad?->getGuia();

        if ($unidad === null || $guia === null || !$guia->isActivo()) {
            return SkillResult::error(sprintf(
                'La casita %s no tiene guía publicada. Que lo resuelva un operador.',
                $unidad?->getNombre() ?? '(sin asignar)'
            ));
        }

        $acceso = PmsGuiaAcceso::paraEvento($evento);
        $contexto = PmsGuiaContexto::construir($unidad, $evento);

        // 🔒 El árbol llega podado y con los placeholders resueltos —o sustituidos por el
        // mensaje de bloqueo—. A partir de aquí ya no hay nada sensible que decidir.
        $secciones = $this->filtro->podar($guia, $acceso, $contexto);

        // 🇪🇸 Al modelo se le da SIEMPRE el español, no el idioma del huésped. Redactar en su
        // lengua ya se lo pide el contexto de la conversación, y traducir es lo que mejor hace.
        //
        // Tres motivos, y el tercero es el que no se ve venir:
        //
        // 1. **El español es el original.** Los otros seis los escribió el traductor
        //    automático; si alguno quedó torcido, se hereda en la respuesta.
        // 2. **Una sola fuente.** Es el mismo criterio que ya siguen `agente_contenido`,
        //    `MessageTemplate::$agenteUso` y las notas de los medios de cobro: al modelo,
        //    español; al huésped, lo que toque.
        // 3. **El recorte deja de depender de quién pregunte.** `recortar()` corta por
        //    caracteres, y las traducciones son más largas —medido: alemán +10% en «Llaves»,
        //    +18% en «Wifi»—. Con el idioma del huésped, un alemán perdía ~120 caracteres más
        //    que un peruano del MISMO ítem. Ese fallo es el peor de reproducir, porque «a mí
        //    me funciona» es literalmente cierto.
        //
        // ⚠️ Esto es lo que se DEVUELVE. La BÚSQUEDA sigue mirando todos los idiomas
        // ({@see self::coincideEnAlgunIdioma()}): el huésped pregunta en el suyo.
        $idioma = 'es';

        // Las horas de entrada y salida viajan SIEMPRE, no sólo dentro del cuerpo del ítem.
        //
        // Estaban únicamente como `{{ check_in }}` en el texto de «Horarios de estancia», y eso
        // ataba ese ítem al camino vivo: darle `agente_contenido` habría borrado las horas, que
        // salen del evento y **cambian por estancia**. Con las horas fuera, el ítem ya puede
        // tener su versión para el agente sin perder nada.
        //
        // Se enumeran dos claves a mano en vez de volcar `$contexto->valores`: ahí dentro
        // también viven `door_code`, `safe_code` y `keybox_main` cuando la ventana está
        // abierta, y un volcado es la forma clásica de que un día salgan sin querer.
        $base = array_filter([
            'casita' => $unidad->getNombre(),
            'huesped' => $reserva->getNombreApellido(),
            'hora_check_in' => $contexto->valores['check_in'] ?? null,
            'hora_check_out' => $contexto->valores['check_out'] ?? null,
        ], static fn ($v) => $v !== null && $v !== '');

        return $this->responder($entrada, $base, $secciones, $idioma, $contexto, $acceso);
    }

    /**
     * La guía de una casita SIN reserva detrás: sólo lo marcado como público.
     *
     * Es la puerta que faltaba. La guía cuelga de `PmsUnidad`, pero el único camino hasta ella
     * atravesaba una reserva, así que un dato tan vendedor como la distribución de camas
     * —«Habitación 1: dos camas dobles»— existía, estaba marcado `publico`, y no había forma de
     * pedirlo. A un prospecto se le contestaba con un escalado a un humano.
     *
     * 🔒 **El filtro es el mismo de siempre.** `PmsGuiaAcceso::publico()` es el peldaño más bajo
     * de LA MATRIZ y `permite()` sólo deja pasar `PmsGuiaVisibilidad::Publico`. No hay aquí
     * ninguna lista de qué se puede enseñar: eso lo marca el operador ítem a ítem desde el
     * panel, y lo aplica el mismo `podar()` que protege la guía del huésped. Una lista blanca
     * aquí sería una segunda fuente de verdad, y la que se olvidaría de actualizar.
     *
     * Sin evento: `PmsGuiaContexto::construir()` lo acepta nulo, y sin estancia no hay horas ni
     * códigos que resolver — que es exactamente lo que se quiere.
     */
    private function guiaPublica(array $entrada, string $casita): SkillResult
    {
        if ($casita === '') {
            return SkillResult::ok([
                'falta_datos' => ['casita'],
                'pregunta' => 'No sé de qué casita me hablas. Pregúntale cuál le interesa y '
                    . 'vuelve a llamarme con «casita».',
            ]);
        }

        $encontradas = $this->resolverUnidad($casita);

        if ($encontradas === []) {
            return SkillResult::error(sprintf('No hay ninguna casita que se llame «%s».', $casita));
        }

        // Con varias coincidencias NO se elige: mismo criterio que buscar_reserva.
        if (count($encontradas) > 1) {
            return SkillResult::error(sprintf(
                '«%s» encaja con varias casitas: %s. Pregunta al usuario cuál.',
                $casita,
                implode(', ', array_map(static fn (PmsUnidad $u) => $u->getNombre() ?? '—', $encontradas))
            ));
        }

        $unidad = $encontradas[0];
        $guia = $unidad->getGuia();

        if ($guia === null || !$guia->isActivo()) {
            return SkillResult::error(sprintf(
                'La casita %s no tiene guía publicada.',
                $unidad->getNombre() ?? '(sin nombre)'
            ));
        }

        $acceso = PmsGuiaAcceso::publico();
        $contexto = PmsGuiaContexto::construir($unidad, null);
        $secciones = $this->filtro->podar($guia, $acceso, $contexto);

        if ($secciones === []) {
            return SkillResult::error(sprintf(
                'La guía de %s no tiene nada marcado como público. Que un operador revise la '
                . 'visibilidad de sus ítems.',
                $unidad->getNombre() ?? '(sin nombre)'
            ));
        }

        // Sin `huesped` ni horas: no hay nadie ni estancia. El nombre de la casita sí, que es
        // lo que `responder()` necesita para poder nombrarla.
        $base = [
            'casita' => $unidad->getNombre(),
            'alcance' => 'Guía PÚBLICA: sólo lo que puede ver alguien que todavía no tiene '
                . 'reserva. Los códigos de acceso y las instrucciones de la estancia NO están '
                . 'aquí, y es correcto que no estén.',
        ];

        return $this->responder($entrada, $base, $secciones, 'es', $contexto, $acceso);
    }

    /**
     * «Casita 1», «casita 1» o «1» resuelven a la misma unidad.
     *
     * Mismo criterio que `ConsultarOcupacionSkill::resolverCasita()`, incluido el anclaje del
     * número al final para que «1» no traiga la 11 ni la 21.
     *
     * @return list<PmsUnidad>
     */
    private function resolverUnidad(string $busqueda): array
    {
        $qb = $this->em->getRepository(PmsUnidad::class)
            ->createQueryBuilder('u')
            ->where('u.activo = true')
            ->orderBy('u.nombre', 'ASC');

        if (preg_match('/^\d+$/', $busqueda) === 1) {
            $qb->andWhere('u.nombre LIKE :sufijo')->setParameter('sufijo', '% ' . $busqueda);
        } else {
            $qb->andWhere('LOWER(u.nombre) LIKE :like')
               ->setParameter('like', '%' . mb_strtolower($busqueda) . '%');
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Los dos caminos de respuesta —catálogo y tema elegido— una vez que el árbol ya está
     * podado.
     *
     * Extraído porque a la guía se llega por DOS puertas: la de una reserva y la pública por
     * casita. Lo que cambia entre ellas es a qué unidad se llega y con qué `PmsGuiaAcceso`;
     * de aquí para abajo el árbol ya viene filtrado y no queda nada sensible que decidir, así
     * que duplicar este tramo sólo serviría para que un día las dos puertas contesten
     * distinto.
     *
     * @param array<string, mixed> $entrada
     * @param array<string, mixed> $base
     * @param array<int, PmsGuiaSeccion> $secciones
     */
    private function responder(
        array $entrada,
        array $base,
        array $secciones,
        string $idioma,
        PmsGuiaContexto $contexto,
        PmsGuiaAcceso $acceso,
    ): SkillResult {
        // El nombre de la casita se lee de `$base` y no de la unidad: las dos puertas lo ponen
        // ahí, y así este tramo no necesita conocer por cuál se entró.
        $casita = (string) ($base['casita'] ?? 'esta casita');
        $busqueda = trim((string) ($entrada['busqueda'] ?? ''));

        // ── Camino 2 de 2: el modelo ya eligió y pide un tema por su id ──────────────────
        $temaId = trim((string) ($entrada['tema_id'] ?? ''));

        if ($temaId !== '') {
            $item = $this->buscarPorId($secciones, $temaId);

            // 🔒 Se busca DENTRO del árbol podado, no con un find(): los ítems se comparten
            // entre casitas, así que un id de otra guía —o de uno que el acceso escondió—
            // devolvería contenido que a este huésped no le toca.
            if ($item === null) {
                return SkillResult::ok($base + [
                    'encontrado' => false,
                    'motivo' => 'tema_no_disponible',
                    'catalogo' => $this->catalogo($secciones, $idioma),
                    'aviso' => 'Ese tema no está en la guía de esta casita. Elige uno de los '
                        . 'del «catalogo» y vuelve a llamarme con su tema_id.',
                ]);
            }

            return SkillResult::ok($base + [
                'encontrado' => true,
                'resultados' => [$this->detalle($item['item'], $item['seccion'], $idioma, $contexto, $acceso)],
            ]);
        }

        // ── Camino 1 de 2: el CATÁLOGO. Es el camino preferido ───────────────────────────
        //
        // Se devuelve qué temas hay, con los términos que el operador escribió, y **decide el
        // modelo** cuál sirve. Es al revés de buscar por diccionario aquí dentro, y es mejor
        // por lo que un `str_contains` no puede saber: si el huésped PREGUNTA o sólo cuenta,
        // si «Aguas Calientes» es el pueblo o el agua de la casa, si «está sucio» va con la
        // limpieza o con una queja de otra cosa. Eso es comprensión, no coincidencia de letras.
        if ($busqueda === '') {
            return SkillResult::ok($base + [
                'catalogo' => $this->catalogo($secciones, $idioma),
                'como_seguir' => 'Éstos son los temas de ESTA casita, con las palabras por las '
                    . 'que suele preguntarse cada uno. Mira lo que te ha dicho el huésped y '
                    . 'DECIDE TÚ cuál responde a lo que quiere; luego pídeme ese tema_id para '
                    . 'leer sus instrucciones. Si ninguno encaja, o si no está preguntando nada '
                    . '—sólo cuenta algo o se queja—, no me llames otra vez: contéstale tú.',
            ]);
        }

        // 🚫 Antes de buscar: ¿esto es un tema o el mensaje entero? Ver MAX_PALABRAS_BUSQUEDA.
        // No se busca «lo mejor que se pueda» con una frase larga: se devuelve el índice y se
        // pide el tema, que es una respuesta útil y no una coincidencia inventada.
        if ($this->cuentaPalabras($busqueda) > self::MAX_PALABRAS_BUSQUEDA) {
            return SkillResult::ok($base + [
                'encontrado' => false,
                'motivo' => 'busqueda_demasiado_larga',
                'temas' => $this->indice($secciones, $idioma),
                'aviso' => 'Me has pasado el mensaje entero, y así encuentro coincidencias por '
                    . 'casualidad: una frase donde el huésped MENCIONA la ducha de otro hotel no '
                    . 'es una pregunta sobre la ducha de esta casa. Decide tú de qué está '
                    . 'preguntando —si es que pregunta algo— y vuelve a llamarme sólo con ese '
                    . 'tema («ducha», «limpieza»). Si sólo está contando algo o quejándose, NO '
                    . 'me llames: contéstale tú.',
            ]);
        }

        $encontrados = $this->buscar($secciones, $busqueda, $idioma, $contexto, $acceso);

        if ($encontrados === []) {
            // Que no haya nada NO autoriza a improvisar: se dice, y se ofrece el índice para
            // que el modelo pueda reconducir hacia un tema que sí exista.
            return SkillResult::ok($base + [
                'encontrado' => false,
                'temas' => $this->indice($secciones, $idioma),
                'aviso' => sprintf(
                    'La guía de %s no dice nada sobre «%s». NO te lo inventes: dile al huésped '
                    . 'que eso no está en su guía y ofrécele preguntar al anfitrión, o mira si '
                    . 'alguno de los «temas» es lo que buscaba.',
                    $casita,
                    $busqueda
                ),
            ]);
        }

        return SkillResult::ok($base + [
            'encontrado' => true,
            'resultados' => array_slice($encontrados, 0, self::MAX_ITEMS),
        ]);
    }

    /**
     * Qué temas tiene esta guía, con lo que hace falta para ELEGIR y nada más.
     *
     * Es la pieza del camino preferido: en vez de que la skill adivine por coincidencia de
     * letras, se le enseña al modelo la carta —tema, sección y los términos que escribió el
     * operador— y elige él, que es quien entiende la pregunta.
     *
     * **Sin cuerpos, a propósito.** El catálogo entero de una casita son ~16 líneas; traer
     * además el texto de cada ítem sería mandar la guía completa en cada conversación para
     * usar un párrafo.
     *
     * `tema_id` es el UUID del ítem: 36 caracteres por fila que se pagan una vez y permiten
     * pedir el contenido exacto, sin volver a describir con palabras lo que ya está elegido.
     *
     * @param array<int, PmsGuiaSeccion> $secciones
     * @return list<array<string, mixed>>
     */
    private function catalogo(array $secciones, string $idioma): array
    {
        $catalogo = [];

        foreach ($secciones as $seccion) {
            $nombreSeccion = $this->enIdioma($seccion->getTitulo(), $idioma);

            foreach ($seccion->getItemsParaCliente() as $item) {
                $tema = $this->enIdioma($item->getTituloParaCliente(), $idioma);

                if ($tema === '') {
                    continue;
                }

                $catalogo[] = array_filter([
                    'tema_id' => (string) $item->getId(),
                    'tema' => $tema,
                    'seccion' => $nombreSeccion ?: null,
                    // Los términos van CRUDOS y sin partir: son la pista de con qué palabras
                    // se pregunta por esto, y quien tiene que leerlos es el modelo.
                    'se_pregunta_como' => $item->getAgenteTerminos(),
                    // Se anuncia YA en el catálogo, no sólo al pedir el detalle: si el modelo
                    // ve desde el índice que el tema necesita a una persona, puede decidir con
                    // eso en la mano en vez de descubrirlo después de haber redactado.
                    'necesita_confirmacion_humana' => $item->isAgenteRequiereHumano() ?: null,
                    'bloqueado' => $item->isBloqueado() ?: null,
                ], static fn ($v) => $v !== null && $v !== '');
            }
        }

        return $catalogo;
    }

    /**
     * Localiza un ítem por su id DENTRO del árbol ya podado.
     *
     * @param array<int, PmsGuiaSeccion> $secciones
     * @return array{item: PmsGuiaItem, seccion: PmsGuiaSeccion}|null
     */
    private function buscarPorId(array $secciones, string $temaId): ?array
    {
        foreach ($secciones as $seccion) {
            foreach ($seccion->getItemsParaCliente() as $item) {
                if ((string) $item->getId() === $temaId) {
                    return ['item' => $item, 'seccion' => $seccion];
                }
            }
        }

        return null;
    }

    /**
     * El contenido de un ítem, listo para leerse en voz alta.
     *
     * @return array<string, mixed>
     */
    private function detalle(
        PmsGuiaItem $item,
        PmsGuiaSeccion $seccion,
        string $idioma,
        PmsGuiaContexto $contexto,
        PmsGuiaAcceso $acceso
    ): array {
        $cuerpo = $this->cuerpoParaElAgente($item, $idioma, $contexto, $acceso);

        return array_filter([
            'tema' => $this->enIdioma($item->getTituloParaCliente(), $idioma),
            'seccion' => $this->enIdioma($seccion->getTitulo(), $idioma) ?: null,
            'bloqueado' => $item->isBloqueado() ?: null,
            'contenido' => $this->recortar($cuerpo),
            // 🔔 La orden viaja PEGADA al texto que el modelo acaba de leer, no en una regla
            // general del prompt que tenga que recordar. Ver PmsGuiaItem::$agenteRequiereHumano.
            'debes_escalar' => $item->isAgenteRequiereHumano()
                ? 'Cuéntale lo que dice la guía, pero NO se lo concedas: esto lo confirma una '
                    . 'persona. Llama a escalar_al_equipo en este mismo turno y dile que ya '
                    . 'avisaste, sin prometerle plazo.'
                : null,
            'enlace' => $item->getUrlBoton(),
            'tiene_fotos' => $item->getTipo() === PmsGuiaItem::TIPO_ALBUM
                || !$item->getGaleria()->isEmpty() ?: null,
        ], static fn ($v) => $v !== null && $v !== '');
    }

    /**
     * Los títulos de todo lo que hay, sin cuerpos.
     *
     * Es la respuesta barata: saber que existe «Uso de la ducha» cuesta una línea, y traer su
     * cuerpo entero cuesta un párrafo. Con el índice el modelo puede reconducir («tengo esto
     * sobre la calefacción, ¿te lo leo?») sin haber pagado por adelantado.
     *
     * @param array<int, PmsGuiaSeccion> $secciones
     * @return array<string, list<string>>
     */
    private function indice(array $secciones, string $idioma): array
    {
        $indice = [];

        foreach ($secciones as $seccion) {
            $titulos = [];

            foreach ($seccion->getItemsParaCliente() as $item) {
                $titulo = $this->enIdioma($item->getTituloParaCliente(), $idioma);
                if ($titulo !== '') {
                    $titulos[] = $item->isBloqueado() ? $titulo . ' (bloqueado)' : $titulo;
                }
            }

            if ($titulos !== []) {
                $indice[$this->enIdioma($seccion->getTitulo(), $idioma) ?: 'Guía'] = $titulos;
            }
        }

        return $indice;
    }

    /**
     * Ítems que hablan de lo que se pregunta, del más pertinente al menos.
     *
     * Se busca en el TÍTULO y en el CUERPO: «cómo va el agua caliente» no encaja con el título
     * «Uso de la ducha», pero sí con su texto. Un acierto en el título pesa más —es de lo que
     * trata el ítem, no una mención de pasada—, y por eso van primero.
     *
     * Sin acentos y en minúsculas por las dos partes: el huésped escribe «calefaccion» y en la
     * guía pone «Calefacción».
     *
     * 🔍 **Se busca en TODOS los idiomas y se responde en UNO.** El contenido está traducido, y
     * quien pregunta no tiene por qué usar el idioma de la ficha: un operador escribe «ducha»
     * sobre la reserva de un huésped inglés, cuya guía dice «Using the shower». Buscando sólo
     * en el idioma del huésped, esa consulta no encontraba nada y el agente respondía que la
     * guía no lo trae —teniéndolo—.
     *
     * @param array<int, PmsGuiaSeccion> $secciones
     * @return list<array<string, mixed>>
     */
    private function buscar(
        array $secciones,
        string $busqueda,
        string $idioma,
        PmsGuiaContexto $contexto,
        PmsGuiaAcceso $acceso
    ): array {
        $aguja = $this->normalizar($busqueda);
        $porTitulo = [];
        $porCuerpo = [];

        foreach ($secciones as $seccion) {
            foreach ($seccion->getItemsParaCliente() as $item) {
                $titulo = $this->enIdioma($item->getTituloParaCliente(), $idioma);

                // Ojo al reparto: se BUSCA en lo publicado (abajo, `$enCuerpo`) y se DEVUELVE
                // lo que toque para el agente. Si se buscara en el override, un ítem dejaría de
                // encontrarse por palabras que sí están en su guía, y el operador no tendría
                // forma de saber por qué.
                $cuerpo = $this->cuerpoParaElAgente($item, $idioma, $contexto, $acceso);

                // Los términos del editor primero: son la respuesta a «con qué palabras
                // preguntan por esto», así que un acierto ahí es el más fiable de los tres.
                $enTerminos = $this->coincideEnTerminos($item, $aguja);
                $enTitulo = $this->coincideEnAlgunIdioma($item->getTituloParaCliente(), $aguja);
                $enCuerpo = $this->coincideEnAlgunIdioma($item->getContenidoParaCliente(), $aguja);

                // 🔎 El override TAMBIÉN se busca, y hace falta decirlo: es lo que el operador
                // escribió para el agente y a menudo cuenta cosas que NO están en el cuerpo
                // publicado —por qué el importe del canal no cuadra, por ejemplo—. Sin esto,
                // escribirlo ahí no servía de nada: la búsqueda no lo veía y el agente
                // contestaba «tu guía no dice nada de eso» teniéndolo delante.
                //
                // Se busca en los dos y no sólo en el override porque el cuerpo publicado suele
                // ser más largo: quitarlo estrecharía lo que se encuentra justo cuando el
                // operador acaba de resumir el texto para el chat.
                $enOverride = $item->getAgenteContenido() !== null
                    && str_contains($this->normalizar($item->getAgenteContenido()), $aguja);

                if (!$enTerminos && !$enTitulo && !$enCuerpo && !$enOverride) {
                    continue;
                }

                $fila = array_filter([
                    'tema' => $titulo,
                    'seccion' => $this->enIdioma($seccion->getTitulo(), $idioma) ?: null,
                    // De dónde salió la coincidencia. `texto` es la más floja —la palabra puede
                    // estar de pasada en mitad de un párrafo— y el modelo tiene que poder
                    // dudar de ella en vez de leerla como una respuesta segura.
                    'coincide_por' => match (true) {
                        $enTerminos => 'termino',
                        $enTitulo => 'titulo',
                        default => 'texto',
                    },
                    // El cuerpo de un ítem bloqueado YA viene sustituido por el mensaje de
                    // bloqueo (lo hace el filtro), así que se puede devolver tal cual: lo que
                    // se marca aquí es que el modelo no debe presentarlo como una instrucción.
                    'bloqueado' => $item->isBloqueado() ?: null,
                    'debes_escalar' => $item->isAgenteRequiereHumano()
                        ? 'Cuéntale lo que dice la guía, pero NO se lo concedas: esto lo '
                            . 'confirma una persona. Llama a escalar_al_equipo en este turno.'
                        : null,
                    'contenido' => $this->recortar($cuerpo),
                    'enlace' => $item->getUrlBoton(),
                    'tiene_fotos' => $item->getTipo() === PmsGuiaItem::TIPO_ALBUM
                        || !$item->getGaleria()->isEmpty() ?: null,
                ], static fn ($v) => $v !== null && $v !== '');

                ($enTerminos || $enTitulo) ? $porTitulo[] = $fila : $porCuerpo[] = $fila;
            }
        }

        return [...$porTitulo, ...$porCuerpo];
    }

    /**
     * El texto de un ítem TAL COMO LO VA A CONTAR EL AGENTE.
     *
     * 🔀 Si el ítem trae `agenteContenido`, manda eso y el cuerpo publicado no se toca: es lo
     * que permite que la pantalla diga una cosa y el chat diga otra —el depósito de garantía
     * leído en la guía es información, soltado a bocajarro espanta—. Ver
     * {@see PmsGuiaItem::$agenteContenido}.
     *
     * El override no pasa por `resolverBloquesDelFront()` ni por `aTextoPlano()`: se escribe en
     * texto plano a propósito, sin HTML ni placeholders. El recorte lo aplica quien lo consume,
     * igual que con el cuerpo.
     *
     * ⚠️ Existe como método y no repetido en línea porque el cuerpo se arma en DOS sitios —el
     * detalle de un tema y el barrido de la búsqueda— y la primera versión de esto sólo parcheó
     * uno: el override no salía al buscar por palabra, que es el camino más usado.
     */
    private function cuerpoParaElAgente(
        PmsGuiaItem $item,
        string $idioma,
        PmsGuiaContexto $contexto,
        PmsGuiaAcceso $acceso
    ): string {
        // 🔒 UN ÍTEM BLOQUEADO NO ENTREGA SU CONTENIDO, y el override tampoco.
        //
        // Este método empezaba por el override y lo devolvía tal cual, así que un tema con
        // candado —«Llaves», «Wifi»— soltaba su texto íntegro al modelo si tenía versión para
        // el agente. El árbol podado ya sustituye el cuerpo del ítem bloqueado, pero
        // `agente_contenido` es un campo aparte que el filtro no toca: se corta aquí.
        //
        // Se devuelve el MOTIVO del candado, que es lo que hay que contarle al huésped.
        if ($item->isBloqueado()) {
            return PmsGuiaMensajes::bloqueo($acceso, $idioma);
        }

        $override = $item->getAgenteContenido();

        if ($override !== null) {
            return $override;
        }

        return $this->aTextoPlano($this->resolverBloquesDelFront(
            $this->enIdioma($item->getContenidoParaCliente(), $idioma),
            $contexto,
            $acceso,
            $idioma
        ));
    }

    /**
     * Cierra los placeholders que el navegador resuelve pintando componentes, porque aquí no
     * hay navegador que los pinte.
     *
     * `PmsGuiaInterpolador` resuelve a propósito sólo las claves de DATO (`{{ door_code }}`) y
     * deja los bloques de maquetación al front. Eso está bien para la web y es un desastre en
     * un chat: el modelo leería `{{ wifi_data }}` en voz alta.
     *
     * - **`{{ wifi_data }}`** → una remisión a `consultar_wifi`. No es una errata del editor:
     *   el front lo traduce a `{{ widget: wifi }}` (`RichContentEngine.ts`) y lo pinta con
     *   `guia.redesWifi`. Aquí no se resuelve porque la credencial es de la otra skill.
     * - **`{{ medios_pago }}`** → una remisión a `consultar_medios_pago`, por lo mismo: los
     *   números de cobro viven en el catálogo `FinMedioCobro`, no en el texto.
     * - **`{{ img: … }}`, `{{ video: … }}`, `{{ map: … }}`, `{{ widget: … }}`** → se borran.
     *   Son maquetación: una URL de imagen no ayuda a explicar cómo va la ducha.
     * - **Cualquier otra clave suelta** → se borra. Para el interpolador es una errata del
     *   editor que «tiene que verse en la revisión», pero al huésped no se le lee una errata.
     *
     * 🪞 Si el front cambia qué placeholders entiende, esto se queda corto sin avisar: los dos
     * lados leen el mismo contenido con reglas propias.
     */
    private function resolverBloquesDelFront(
        string $texto,
        PmsGuiaContexto $contexto,
        PmsGuiaAcceso $acceso,
        string $idioma
    ): string {
        if (!str_contains($texto, '{{')) {
            return $texto;
        }

        $texto = (string) preg_replace(
            '/\{\{\s*wifi_data\s*\}\}/i',
            '(los datos del WiFi se piden con consultar_wifi)',
            $texto
        );

        // Mismo caso que el WiFi: el ítem de pagos explica la POLÍTICA (cuándo se paga, el
        // depósito) y deja los números al placeholder. Sin esta remisión el modelo leía el
        // texto, veía que no había ninguna cuenta y se la inventaba, teniendo la skill al lado.
        $texto = (string) preg_replace(
            '/\{\{\s*medios_pago\s*\}\}/i',
            '(los números de cuenta y de Yape se piden con consultar_medios_pago)',
            $texto
        );

        // Bloques con `:` (maquetación) y claves sueltas que nadie resolvió.
        $texto = (string) preg_replace('/\{\{\s*[a-z0-9_]+\s*:.*?\}\}/is', ' ', $texto);

        return (string) preg_replace('/\{\{\s*[a-z0-9_]+\s*\}\}/i', ' ', $texto);
    }

    /**
     * ¿Alguno de los términos del editor es lo que se pregunta?
     *
     * La comparación va en los DOS sentidos: el término «agua caliente» tiene que encontrarse
     * cuando preguntan «no sale el agua caliente» (el término dentro de la pregunta), y el
     * término «wifi» cuando preguntan sólo «wifi» (la pregunta dentro del término). Con una
     * sola dirección, la mitad de las consultas reales se caen.
     */
    private function coincideEnTerminos(PmsGuiaItem $item, string $aguja): bool
    {
        foreach ($item->getAgenteTerminosLista() as $termino) {
            $termino = $this->normalizar($termino);

            if ($termino === '') {
                continue;
            }

            // 🔥 Los términos MUY cortos no se comparan como subcadena: «id» —de «passport,
            // id»— está dentro de «sal(id)a», así que preguntar «salida tarde» devolvía
            // «Registro de huéspedes». Con menos de 4 letras se exige la palabra entera.
            if (mb_strlen($termino) < 4) {
                if ($this->contienePalabraExacta($aguja, $termino)) {
                    return true;
                }

                continue;
            }

            if (str_contains($aguja, $termino) || str_contains($termino, $aguja)) {
                return true;
            }

            // La subcadena no basta cuando la pregunta mete palabras en medio: el término
            // «how to get in» no aparece dentro de «how do i get in», y son lo mismo.
            if ($this->palabrasContenidas($termino, $aguja)) {
                return true;
            }
        }

        return false;
    }

    /**
     * El término aparece como PALABRA, no como trozo de otra.
     *
     * Sólo para los términos cortos («id», «dni», «wc»), que son los que enganchan por dentro
     * de cualquier palabra. `\b` no vale con acentos en UTF-8 —«ó» rompe el límite—, así que
     * se delimita por lo que separa palabras de verdad: principio, fin o algo que no sea letra
     * ni número.
     */
    private function contienePalabraExacta(string $texto, string $palabra): bool
    {
        return (bool) preg_match(
            '/(?<![\p{L}\p{N}])' . preg_quote($palabra, '/') . '(?![\p{L}\p{N}])/u',
            $texto
        );
    }

    /**
     * ¿Están en la pregunta todas las palabras con carga del término?
     *
     * Se ignoran las de menos de tres letras («de», «la», «in», «to»): son las que cambian de
     * una frase a otra sin cambiar lo que se pregunta. Se exigen TODAS y no alguna: con una
     * sola, el término «agua caliente» saltaría ante cualquier pregunta que mencione el agua.
     */
    private function palabrasContenidas(string $termino, string $aguja): bool
    {
        $palabras = array_filter(
            preg_split('/\s+/u', $termino) ?: [],
            static fn (string $p): bool => mb_strlen($p) >= 3
        );

        if ($palabras === []) {
            return false;
        }

        foreach ($palabras as $palabra) {
            if (!str_contains($aguja, $palabra)) {
                return false;
            }
        }

        return true;
    }

    /**
     * ¿Alguna traducción de este campo habla de lo que se busca?
     *
     * El HTML se limpia antes de comparar: si no, buscar «div» encontraría media guía, y una
     * palabra partida por un `<strong>` no se encontraría nunca.
     *
     * @param array<int, array{language?: string, content?: string}> $i18n
     */
    private function coincideEnAlgunIdioma(array $i18n, string $aguja): bool
    {
        foreach ($i18n as $bloque) {
            $contenido = (string) ($bloque['content'] ?? '');

            if ($contenido !== '' && str_contains($this->normalizar($this->aTextoPlano($contenido)), $aguja)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Los nombres de las casitas, para preguntar o para avisar de cuáles son las suyas.
     *
     * @param array<int, PmsEventoCalendario> $eventos
     * @return list<string>
     */
    private function nombresDe(array $eventos): array
    {
        return array_values(array_unique(array_filter(array_map(
            static fn (PmsEventoCalendario $e): ?string => $e->getPmsUnidad()?->getNombre(),
            $eventos
        ))));
    }

    /**
     * El contenido en el idioma del huésped, con caída al español.
     *
     * La guía viaja como `[{language, content}]` en todos los idiomas a la vez —el selector de
     * la web cambia el texto sin pedir nada al servidor—, pero al modelo se le da UNO: mandarle
     * cinco traducciones del mismo párrafo es pagar cinco veces por la misma frase.
     *
     * @param array<int, array{language?: string, content?: string}> $i18n
     */
    private function enIdioma(array $i18n, string $idioma): string
    {
        $fallback = '';

        foreach ($i18n as $bloque) {
            $contenido = trim((string) ($bloque['content'] ?? ''));

            if ($contenido === '') {
                continue;
            }

            if (($bloque['language'] ?? '') === $idioma) {
                return $contenido;
            }

            if (($bloque['language'] ?? '') === 'es' || $fallback === '') {
                $fallback = $contenido;
            }
        }

        return $fallback;
    }

    /** El HTML del editor, legible: sin etiquetas y sin los saltos que deja el WYSIWYG. */
    private function aTextoPlano(string $html): string
    {
        // Los bloques se separan con un espacio ANTES de quitar etiquetas: si no,
        // `<p>uno</p><p>dos</p>` queda como «unodos».
        $conSaltos = preg_replace('#</(p|div|li|h[1-6]|tr)>#i', "$0 ", $html) ?? $html;
        $texto = strip_tags(str_replace(['<br>', '<br/>', '<br />'], ' ', $conSaltos));

        return trim((string) preg_replace('/\s+/u', ' ', html_entity_decode($texto, ENT_QUOTES | ENT_HTML5)));
    }

    /** Corta por palabra, no a mitad de una, y avisa de que hay más en la guía. */
    private function recortar(string $texto): string
    {
        if (mb_strlen($texto) <= self::MAX_CARACTERES) {
            return $texto;
        }

        $corte = mb_substr($texto, 0, self::MAX_CARACTERES);
        $ultimoEspacio = mb_strrpos($corte, ' ');

        return ($ultimoEspacio !== false ? mb_substr($corte, 0, $ultimoEspacio) : $corte)
            . '… (sigue en la guía)';
    }

    /** Palabras de verdad, para distinguir un tema de un mensaje pegado. */
    private function cuentaPalabras(string $texto): int
    {
        return count(array_filter(preg_split('/\s+/u', trim($texto)) ?: []));
    }

    /** Minúsculas y sin acentos, para comparar como escribe la gente. */
    private function normalizar(string $texto): string
    {
        return (string) (new UnicodeString($texto))->ascii()->lower();
    }
}
