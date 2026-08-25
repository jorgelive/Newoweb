<?php

declare(strict_types=1);

namespace App\Agent\Skill\Cotizacion;

use App\Agent\Access\ActorInterface;
use App\Agent\Access\NivelRiesgo;
use App\Agent\Skill\SkillDefinition;
use App\Agent\Skill\SkillDominioInterface;
use App\Agent\Skill\SkillInterface;
use App\Agent\Skill\SkillParameter;
use App\Agent\Skill\SkillResult;
use App\Cotizacion\Entity\CotizacionFile;
use App\Cotizacion\Entity\CotizacionFileGrupo;
use App\Cotizacion\Entity\CotizacionFilepasajero;
use App\Cotizacion\Enum\GrupoTipoEnum;
use App\Cotizacion\Enum\PasajeroTipoEnum;
use App\Security\Roles;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Responde preguntas sobre el padrón: quién está en el grupo 6, quién NO lleva vuelo nacional.
 *
 * ### Por qué los filtros son parámetros y no una frase
 *
 * Sería más cómodo aceptar «los del grupo 6 sin vuelo» y resolverlo aquí. Sería también volver a
 * escribir un intérprete de lenguaje natural **dentro** de la skill, con la diferencia de que el
 * suyo se equivoca en silencio: «grupo 6» y «habitación 6» son los dos «6», y elegir mal devuelve
 * una lista plausible de gente equivocada. Con parámetros separados el modelo tiene que decir en
 * qué eje busca, y si no lo sabe, preguntar.
 *
 * ### ⚠️ Lo que el modelo elige se valida contra lista blanca
 *
 * `subgrupo` y `servicio` se comprueban contra lo que ese expediente tiene de verdad. Si no
 * encajan, la skill **no devuelve una lista vacía**: devuelve las opciones reales. Una lista vacía
 * se lee como «no hay nadie», que es una respuesta falsa y creíble; las opciones se leen como «te
 * has equivocado de nombre», que es la verdad.
 *
 * ### La negación es un parámetro, no una búsqueda al revés
 *
 * «Quiénes NO tienen vuelo nacional» no se puede contestar buscando: hay que partir de TODOS y
 * quitar. Por eso `negar_servicio` existe — y por eso vacío se lee como NO, igual que en el
 * padrón: si nadie marcó la casilla, esa persona no lo lleva.
 *
 * ⚠️ **Los «no participa» quedan fuera por defecto**, como en el panel. Están en el padrón porque
 * se apuntaron y se cayeron, pero conservan grupo y reservas aéreas: contarlos infla cualquier
 * respuesta sobre cuánta gente va.
 */
final readonly class ConsultarPadronSkill implements SkillInterface, SkillDominioInterface
{
    /**
     * Nombres devueltos como máximo.
     *
     * Un padrón de colegio son 133 personas: volcarlas es media ventana de contexto para una
     * pregunta que casi siempre se responde con el número. Se devuelve el TOTAL siempre y los
     * nombres recortados, avisando de cuántos faltan.
     */
    private const int MAX_NOMBRES = 40;

    /** Valores por eje al enumerar opciones tras un nombre errado. Ver `opcionesDeSubgrupo()`. */
    private const int MAX_OPCIONES_POR_EJE = 12;

    /**
     * Hasta cuánta gente se devuelven los HORARIOS de sus vuelos.
     *
     * Son varias líneas por vuelo y hasta dos vuelos por persona. Preguntar por alguien concreto
     * y recibirlos es lo que se quiere; recibir los de veinte es volver a inflar el turno.
     */
    private const int MAX_CON_HORARIOS = 4;

    /** Los ejes por los que se puede desglosar. Lista blanca: se valida ANTES de contar. */
    private const array EJES_AGRUPABLES = ['grupo', 'habitacion', 'vuelo', 'rol', 'servicio'];

    public function __construct(private EntityManagerInterface $em) {}

    public function nombre(): string
    {
        return 'consultar_padron';
    }

    public function definicion(): SkillDefinition
    {
        return new SkillDefinition(
            descripcion: 'Consulta el padrón (name list) de un expediente de grupo. Responde por '
                . 'UNA PERSONA —«¿quién es Fabio Latorre?», «¿en qué habitación está Susan?», «el '
                . 'pasaporte de Santiago»— con el parámetro `persona`, y también '
                . 'quién cumple una condición: quiénes están en un subgrupo («el grupo 6», «la '
                . 'habitación HA13», «los de Arajet»), quiénes llevan un servicio y —muy '
                . 'importante— quiénes NO lo llevan, quiénes tienen un rol concreto, y quiénes '
                . 'tienen documentos vencidos o por vencer. NECESITA el localizador exacto: '
                . 'sácalo antes con buscar_expediente, no lo inventes. Los filtros se ACUMULAN: '
                . 'subgrupo + servicio + rol se cumplen todos a la vez. Para «quiénes NO tienen '
                . 'vuelo nacional» pon servicio="Vuelo Nacional" y negar_servicio=true; buscar el '
                . 'negativo de otra forma no funciona, porque hay que partir de todos y quitar. '
                . 'Si te equivocas de nombre en subgrupo o servicio NO te devuelvo una lista '
                . 'vacía: te devuelvo las opciones que existen, para que preguntes o corrijas. '
                . 'Por defecto los «no participa» NO se cuentan: se apuntaron y se cayeron, pero '
                . 'conservan grupo y reservas, así que contarlos infla la respuesta. Si la '
                . 'pregunta es cuántos son y no quiénes, usa solo_conteo=true y ahorra la lista.',
            parametros: [
                SkillParameter::texto('expediente', 'Localizador exacto del expediente, tal como '
                    . 'lo devolvió buscar_expediente. Ejemplo: «5SRAJV».'),
                SkillParameter::texto('persona', 'Nombre, apellido o número de documento de UNA '
                    . 'persona. Úsalo cuando pregunten por alguien concreto: «¿quién es Fabio '
                    . 'Latorre?», «el pasaporte de Santiago», «en qué habitación está Susan», «los '
                    . 'vuelos de Santiago Gómez». Aguanta el orden cambiado, las tildes y los '
                    . 'nombres a medias. Cuando lo usas y sale poca gente, cada uno viene con sus '
                    . 'vuelos COMPLETOS: tramo, aerolínea, localizador y los horarios de ida y '
                    . 'retorno.',
                    requerido: false),
                SkillParameter::texto('subgrupo', 'Un subgrupo concreto: «6», «Grupo 6», «HA13», '
                    . '«IFBI5Q» o el nombre de la aerolínea «Arajet». Si el mismo valor existe en '
                    . 'dos ejes te lo diré para que preguntes cuál.', requerido: false),
                SkillParameter::texto('servicio', 'Un servicio del viaje: «Vuelo Nacional», '
                    . '«Coco Bongo», «Seguro». Devuelve quién lo lleva.', requerido: false),
                SkillParameter::booleano('negar_servicio', 'true para devolver a quienes NO '
                    . 'llevan el servicio indicado. Es la única forma de contestar «quiénes no '
                    . 'tienen X». Por defecto false.', requerido: false),
                SkillParameter::texto('rol', 'Rol dentro del grupo: participante, acompañante, '
                    . 'coordinador, supervisor, invitado o no_participa.', requerido: false),
                SkillParameter::texto('documentos', 'Filtra por estado documental: «vencidos» '
                    . '(ya caducados), «vencen_pronto» (en menos de un año) o «sin_comprobar» '
                    . '(sin fecha de vencimiento, que NO es lo mismo que vigente).',
                    requerido: false),
                SkillParameter::booleano('incluir_no_participa', 'true para contar también a los '
                    . 'que figuran como «no participa». Por defecto false.', requerido: false),
                SkillParameter::booleano('solo_conteo', 'true para devolver sólo cuántos son, sin '
                    . 'los nombres. Úsalo cuando la pregunta sea «cuántos».', requerido: false),
                SkillParameter::texto('agrupar_por', 'Devuelve el DESGLOSE en una sola llamada, en '
                    . 'vez de un solo número. Valores: «grupo», «habitacion», «vuelo», «rol» o '
                    . '«servicio». Úsalo SIEMPRE que la pregunta sea «cuántos hay en cada…», '
                    . '«el reparto por…», «cómo se distribuyen…»: pedir uno por uno agota mis '
                    . 'vueltas antes de llegar a contestar. Se combina con los demás filtros: '
                    . 'agrupar_por=habitacion con rol=coordinador da las habitaciones de los '
                    . 'coordinadores.', requerido: false),
            ],
        );
    }

    /** @return list<string> */
    public function dominios(): array
    {
        return [];
    }

    public function rolesRequeridos(): array
    {
        return [Roles::RESERVAS_SHOW];
    }

    public function nivelRiesgo(): NivelRiesgo
    {
        return NivelRiesgo::Lectura;
    }

    /**
     * @param array<string, mixed> $entrada
     */
    public function ejecutar(array $entrada, ActorInterface $actor): SkillResult
    {
        $localizador = trim((string) ($entrada['expediente'] ?? ''));

        $file = $this->em->getRepository(CotizacionFile::class)->findOneBy(['localizador' => $localizador]);

        if ($file === null) {
            return SkillResult::error(sprintf(
                'No existe ningún expediente con el localizador «%s». Búscalo antes con buscar_expediente.',
                $localizador,
            ));
        }

        $incluirNoParticipa = (bool) ($entrada['incluir_no_participa'] ?? false);

        /** @var list<CotizacionFilepasajero> $gente */
        $gente = array_values(array_filter(
            $file->getFilepasajeros()->toArray(),
            static fn (CotizacionFilepasajero $p): bool
                => $incluirNoParticipa || $p->getTipo() !== PasajeroTipoEnum::NO_PARTICIPA,
        ));

        // ── Subgrupo: contra lista blanca, y si falla se devuelven las opciones ──
        $subgrupoPedido = trim((string) ($entrada['subgrupo'] ?? ''));
        $grupos = null;
        if ($subgrupoPedido !== '') {
            $grupos = $this->gruposQueEncajan($file, $subgrupoPedido);

            if ($grupos === []) {
                return SkillResult::ok([
                    'error_de_nombre' => sprintf('«%s» no es ningún subgrupo de este expediente.', $subgrupoPedido),
                    'opciones' => $this->opcionesDeSubgrupo($file),
                ]);
            }
        }

        // ── Servicio: igual, y con la negación ──────────────────────────────
        $servicioPedido = trim((string) ($entrada['servicio'] ?? ''));
        $servicio = null;
        if ($servicioPedido !== '') {
            $servicio = $this->servicioQueEncaja($file, $servicioPedido);

            if ($servicio === null) {
                return SkillResult::ok([
                    'error_de_nombre' => sprintf('«%s» no es ningún servicio de este expediente.', $servicioPedido),
                    'opciones' => $this->opcionesDeServicio($file),
                ]);
            }
        }

        $persona = trim((string) ($entrada['persona'] ?? ''));
        $negar = (bool) ($entrada['negar_servicio'] ?? false);
        $rol = $this->rolPedido($entrada);
        $documentos = mb_strtolower(trim((string) ($entrada['documentos'] ?? '')));

        $cumplen = array_values(array_filter($gente, function (CotizacionFilepasajero $p) use (
            $grupos, $servicio, $negar, $rol, $documentos, $persona
        ): bool {
            if ($grupos !== null && !$this->perteneceAAlguno($p, $grupos)) {
                return false;
            }

            if ($servicio !== null) {
                $lleva = $this->perteneceAAlguno($p, [$servicio]);
                if ($lleva === $negar) {
                    return false;
                }
            }

            if ($rol !== null && $p->getTipo() !== $rol) {
                return false;
            }

            if ($persona !== '' && !$this->esEstaPersona($p, $persona)) {
                return false;
            }

            return $documentos === '' || in_array($documentos, $this->estadoDocumental($p), true);
        }));

        $salida = [
            'expediente' => $file->getNombreGrupo(),
            'localizador' => $file->getLocalizadorPublico(),
            'total_considerado' => count($gente),
            'cumplen' => count($cumplen),
            'filtro' => $this->describirFiltro($persona, $grupos, $servicio, $negar, $rol, $documentos),
        ];

        if (!$incluirNoParticipa) {
            $salida['nota'] = 'No se cuentan los «no participa».';
        }

        // ⚠️ El desglose se resuelve AQUÍ, en una llamada.
        //
        // Sin esto, «cuántos hay en cada grupo» obliga al modelo a llamarme una vez por grupo: con
        // nueve grupos son nueve vueltas, el turno topa en ocho y **se queda sin contestar** —
        // habiendo pagado el catálogo entero ocho veces. Pasó en producción el 24/08/2026 y costó
        // 188 000 tokens en un solo turno.
        $eje = $this->comparable((string) ($entrada['agrupar_por'] ?? ''));
        if ($eje !== '') {
            // ⚠️ El eje se valida contra la lista blanca ANTES de contar, no según si sale alguien.
            //
            // Antes se devolvía «no es un eje por el que pueda agrupar» cuando el desglose salía
            // vacío, y eso ocurre con un eje PERFECTAMENTE VÁLIDO en cuanto los filtros dejan a
            // gente que no pertenece a él: los 2 supervisores no tienen grupo, así que
            // `rol=supervisor` + `agrupar_por=grupo` contestaba que «grupo» no era un eje… con
            // «grupo» dentro de la lista de opciones del propio error. El modelo reintenta con
            // variantes, que es el bucle que este parámetro vino a matar.
            if (!in_array($eje, self::EJES_AGRUPABLES, true)) {
                return SkillResult::ok([
                    'error_de_nombre' => sprintf('«%s» no es un eje por el que pueda agrupar.', $eje),
                    'opciones' => self::EJES_AGRUPABLES,
                ]);
            }

            ['desglose' => $desglose, 'sin_eje' => $sinEje, 'multiple' => $multiple]
                = $this->desglosar($cumplen, $eje);

            $salida['agrupado_por'] = $eje;
            $salida['desglose'] = $desglose;

            // ⚠️ La suma del desglose NO tiene por qué cuadrar con `cumplen`, y callarlo produce
            // una respuesta falsa y creíble —la que esta skill existe para evitar—:
            //
            //   · por DEBAJO: quien no pertenece a ningún grupo de ese eje no sale. Medido, «por
            //     grupo» sumaba 108 sobre 131 y las 23 personas sin grupo —acompañantes,
            //     supervisores, invitados— quedaban invisibles.
            //   · por ENCIMA: quien pertenece a dos del mismo eje cuenta dos veces. «Por vuelo»
            //     sumaba 161 sobre 131, porque 30 llevan nacional e internacional.
            //
            // Para notarlo, el modelo tendría que sumar y comparar. Se le dice.
            if ($sinEje > 0) {
                $salida['sin_'.$eje] = $sinEje;
                $salida['nota_desglose'] = sprintf(
                    '%d de las %d personas no están en ningún «%s»: el desglose suma menos que el total.',
                    $sinEje,
                    count($cumplen),
                    $eje,
                );
            }

            if ($multiple > 0) {
                $salida['nota_desglose'] = trim(($salida['nota_desglose'] ?? '').' '.sprintf(
                    '%d personas pertenecen a más de un «%s», así que el desglose suma MÁS que el total: '
                    .'no sumes las cifras para dar un número de gente.',
                    $multiple,
                    $eje,
                ));
            }

            return SkillResult::ok($salida);
        }

        if ((bool) ($entrada['solo_conteo'] ?? false)) {
            return SkillResult::ok($salida);
        }

        usort($cumplen, static fn (CotizacionFilepasajero $a, CotizacionFilepasajero $b): int
            => strcmp((string) $a->getApellido(), (string) $b->getApellido()));

        // Con `persona` la pregunta es sobre alguien concreto, así que se dan los horarios de sus
        // vuelos enteros. Sin él es un listado y se quedan en aerolínea + localizador.
        $conHorarios = $persona !== '' && count($cumplen) <= self::MAX_CON_HORARIOS;

        $salida['personas'] = array_map(
            fn (CotizacionFilepasajero $p): array => $this->describirPersona($p, $conHorarios),
            array_slice($cumplen, 0, self::MAX_NOMBRES),
        );

        if (count($cumplen) > self::MAX_NOMBRES) {
            $salida['y_mas'] = count($cumplen) - self::MAX_NOMBRES;
            $salida['aviso'] = 'La lista va recortada. Di el total y ofrece acotar más.';
        }

        return SkillResult::ok($salida);
    }

    /**
     * Cuánta gente hay en cada valor de un eje, y **cuánta se queda fuera de la cuenta**.
     *
     * ⚠️ Devuelve las tres cifras juntas a propósito. El desglose por sí solo miente en las dos
     * direcciones —quien no pertenece al eje no sale, quien pertenece a dos cuenta dos veces— y
     * quien llama tiene que poder decirlo.
     *
     * @param list<CotizacionFilepasajero> $gente
     * @return array{desglose: array<string, int>, sin_eje: int, multiple: int} El desglose va
     *         ordenado de más a menos, que es como se lee un reparto.
     */
    private function desglosar(array $gente, string $eje): array
    {
        if ($eje === 'rol') {
            $cuenta = [];
            foreach ($gente as $p) {
                $clave = $p->getTipo()?->label() ?? 'sin rol';
                $cuenta[$clave] = ($cuenta[$clave] ?? 0) + 1;
            }
            arsort($cuenta);

            // El rol es uno y sólo uno por persona: nadie se queda fuera ni cuenta dos veces.
            return ['desglose' => $cuenta, 'sin_eje' => 0, 'multiple' => 0];
        }

        // Los ejes de grupo: se compara contra la ETIQUETA del eje —«Grupo», «Habitación», «Vuelo
        // Nacional»— así que «vuelo» casa con los dos tramos y los desglosa juntos, que es lo que
        // se quiere preguntar.
        $esServicio = $eje === 'servicio';
        $cuenta = [];
        $sinEje = 0;
        $multiple = 0;

        foreach ($gente as $p) {
            $suyos = 0;

            foreach ($p->grupos() as $g) {
                $deServicio = $g->getTipo() === GrupoTipoEnum::SERVICIO;

                if ($esServicio !== $deServicio) {
                    continue;
                }

                if (!$esServicio && !str_contains($this->comparable($g->getEtiquetaDeEje()), $eje)) {
                    continue;
                }

                ++$suyos;
                $clave = $esServicio ? (string) $g->getClave() : $this->rotuloDe($g);
                $cuenta[$clave] = ($cuenta[$clave] ?? 0) + 1;
            }

            if ($suyos === 0) {
                ++$sinEje;
            } elseif ($suyos > 1) {
                ++$multiple;
            }
        }

        arsort($cuenta);

        return ['desglose' => $cuenta, 'sin_eje' => $sinEje, 'multiple' => $multiple];
    }

    /**
     * Cómo se nombra un subgrupo en el desglose, sin repetirse.
     *
     * Concatenar eje + clave + nombre a lo bruto daba «Grupo Grupo 5»: la etiqueta del eje es
     * «Grupo», la clave «5» y el nombre «Grupo 5». Se dice tres veces lo mismo y se paga en
     * tokens por cada línea del desglose.
     */
    private function rotuloDe(CotizacionFileGrupo $g): string
    {
        $eje = $g->getEtiquetaDeEje();
        $nombre = trim((string) $g->getNombre());
        $clave = (string) $g->getClave();

        // El nombre ya lo dice todo («Grupo 5»): no se le antepone nada.
        if ($nombre !== '' && str_starts_with($this->comparable($nombre), $this->comparable($eje))) {
            return $nombre;
        }

        // El nombre lleva la clave dentro: sobra repetirla.
        if ($nombre !== '' && str_contains($this->comparable($nombre), $this->comparable($clave))) {
            return trim($eje.' '.$nombre);
        }

        return trim($eje.' '.$clave.' '.$nombre);
    }

    /**
     * ¿Es esta la persona que buscan?
     *
     * ⚠️ Por TODAS las palabras sueltas, no por la cadena entera. «Fabio Latorre» no aparece
     * literal en ningún sitio: el nombre guardado es «Henry Fabio Israel» y el apellido «Latorre
     * Garcia». Buscando la cadena completa no se encuentra a nadie, que es lo que pasaba —y el
     * modelo entonces reintenta metiendo el nombre en `subgrupo`, que devuelve «no existe» con las
     * opciones, y vuelta a empezar: seis llamadas y ninguna respuesta.
     *
     * Se exige que estén TODAS las palabras, en cualquier orden y en cualquier campo. Así «Latorre
     * Fabio» y «fabio latorre» encuentran al mismo, y «Santiago» solo devuelve los cuatro que hay
     * —que es correcto: son cuatro, y el modelo debe preguntar cuál—.
     */
    private function esEstaPersona(CotizacionFilepasajero $pasajero, string $buscado): bool
    {
        $pajar = $this->comparable($pasajero->getNombre().' '.$pasajero->getApellido());

        foreach ($pasajero->getIdentificaciones() as $doc) {
            $pajar .= ' '.$this->comparable($doc->getNumero());
        }

        foreach (preg_split('/\s+/u', $this->comparable($buscado)) ?: [] as $palabra) {
            if ($palabra !== '' && !str_contains($pajar, $palabra)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Para comparar lo que escribe un modelo con lo que hay guardado.
     *
     * ⚠️ Sin quitar tildes, «habitacion HA13» no encontraba «Habitación HA13» y la skill devolvía
     * «ese subgrupo no existe» con 89 opciones. El modelo entonces reintenta con otra variante, y
     * cada reintento es una vuelta entera del bucle con el catálogo de 15 000 tokens detrás.
     *
     * ⚠️ **Una tabla y no `Transliterator`.** Aquélla exige `ext-intl`, que este proyecto NO
     * declara en `composer.json` —sí `ext-iconv`, no `intl`—: hoy está cargada en los dos
     * entornos, pero un servidor nuevo sin ella no daría un fallback, daría `Error: Class
     * "Transliterator" not found` en **todas** las llamadas a esta skill, no sólo al agrupar.
     * Una dependencia oculta de una extensión, para quitar seis tildes.
     *
     * También es determinista: `iconv('ASCII//TRANSLIT')` depende de la locale del sistema y en
     * algunas devuelve `'a` en vez de `a`.
     */
    private const array SIN_TILDE = [
        'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n',
        'à' => 'a', 'è' => 'e', 'ì' => 'i', 'ò' => 'o', 'ù' => 'u', 'ç' => 'c',
        'Á' => 'a', 'É' => 'e', 'Í' => 'i', 'Ó' => 'o', 'Ú' => 'u', 'Ü' => 'u', 'Ñ' => 'n',
        'À' => 'a', 'È' => 'e', 'Ì' => 'i', 'Ò' => 'o', 'Ù' => 'u', 'Ç' => 'c',
    ];

    private function comparable(?string $texto): string
    {
        return mb_strtolower(trim(strtr((string) $texto, self::SIN_TILDE)));
    }

    /**
     * Los grupos que encajan con lo que pidió el modelo.
     *
     * Devuelve una LISTA porque «6» puede ser el grupo 6 y la habitación 6 a la vez, y «Arajet»
     * son ocho localizadores distintos. Quedarse con el primero sería elegir por el operador.
     *
     * @return list<CotizacionFileGrupo>
     */
    private function gruposQueEncajan(CotizacionFile $file, string $pedido): array
    {
        $buscado = $this->comparable($pedido);
        $encajan = [];

        foreach ($file->getGrupos() as $g) {
            if ($g->getTipo() === GrupoTipoEnum::SERVICIO) {
                continue;
            }

            $candidatos = [
                $this->comparable($g->getClave()),
                $this->comparable($g->getNombre()),
                $this->comparable(trim($g->getEtiquetaDeEje().' '.$g->getClave())),
            ];

            if (in_array($buscado, array_filter($candidatos), true)) {
                $encajan[] = $g;
            }
        }

        return $encajan;
    }

    private function servicioQueEncaja(CotizacionFile $file, string $pedido): ?CotizacionFileGrupo
    {
        $buscado = $this->comparable($pedido);

        foreach ($file->getGrupos() as $g) {
            if ($g->getTipo() === GrupoTipoEnum::SERVICIO && $this->comparable($g->getClave()) === $buscado) {
                return $g;
            }
        }

        return null;
    }

    /** @param list<CotizacionFileGrupo> $grupos */
    private function perteneceAAlguno(CotizacionFilepasajero $pasajero, array $grupos): bool
    {
        foreach ($pasajero->grupos() as $suyo) {
            foreach ($grupos as $g) {
                if ($suyo === $g) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * ⚠️ «sin_comprobar» NO es «vigente»: es que no lo sabemos, y es justo lo que hay que mirar
     * antes de un viaje. Son tres estados distintos y ninguno se lee como los otros.
     *
     * @return list<string>
     */
    private function estadoDocumental(CotizacionFilepasajero $pasajero): array
    {
        $hoy = new DateTimeImmutable('today');
        $enUnAnio = $hoy->modify('+1 year');
        $estados = [];

        foreach ($pasajero->getIdentificaciones() as $doc) {
            $vence = $doc->getVencimiento();

            if ($vence === null) {
                $estados[] = 'sin_comprobar';
                continue;
            }

            if ($vence < $hoy) {
                $estados[] = 'vencidos';
            } elseif ($vence < $enUnAnio) {
                $estados[] = 'vencen_pronto';
            }
        }

        return array_values(array_unique($estados));
    }

    /** @param array<string, mixed> $entrada */
    private function rolPedido(array $entrada): ?PasajeroTipoEnum
    {
        $pedido = $this->comparable((string) ($entrada['rol'] ?? ''));

        if ($pedido === '') {
            return null;
        }

        foreach (PasajeroTipoEnum::cases() as $caso) {
            if ($caso->value === $pedido || $this->comparable($caso->label()) === $pedido) {
                return $caso;
            }
        }

        return null;
    }

    /** @return array<string, mixed> */
    private function describirPersona(CotizacionFilepasajero $p, bool $conHorarios = false): array
    {
        $documentos = [];
        foreach ($p->getIdentificaciones() as $doc) {
            $documentos[] = trim(sprintf(
                '%s %s%s',
                $doc->getTipo()->value,
                (string) $doc->getNumero(),
                $doc->getVencimiento() !== null ? ' (vence '.$doc->getVencimiento()->format('d/m/Y').')' : '',
            ));
        }

        // Los vuelos van aparte de los demás subgrupos: son lo que más se pregunta de una persona
        // y meterlos en la misma lista los entierra entre habitaciones y servicios.
        $subgrupos = [];
        $vuelos = [];
        foreach ($p->grupos() as $g) {
            if ($g->getTipo() === GrupoTipoEnum::SERVICIO) {
                continue;
            }

            if ($g->getTipo()?->esReservaAerea()) {
                $vuelo = array_filter([
                    'tramo' => trim(str_ireplace('vuelo', '', $g->getEtiquetaDeEje())) ?: 'Vuelo',
                    'aerolinea' => $g->getNombre(),
                    'localizador' => $g->getClave(),
                    // ⚠️ Los HORARIOS sólo cuando preguntan por alguien concreto. Son varias líneas
                    // por vuelo: en una lista de 40 personas son miles de tokens que nadie pidió, y
                    // volverían a inflar el turno que tanto costó adelgazar.
                    'horarios' => $conHorarios ? $g->getDetalle() : null,
                ], static fn (mixed $v): bool => $v !== null && $v !== '');

                $vuelos[] = $vuelo;
                continue;
            }

            $subgrupos[] = trim($g->getEtiquetaDeEje().' '.$g->getClave().' '.($g->getNombre() ?? ''));
        }

        return array_filter([
            'nombre' => trim($p->getNombre().' '.$p->getApellido()),
            'rol' => $p->getTipo()?->label(),
            'edad' => $p->getEdad(),
            'telefono' => $p->getTelefono(),
            'documentos' => $documentos,
            'vuelos' => $vuelos,
            'subgrupos' => $subgrupos,
        ], static fn (mixed $v): bool => $v !== null && $v !== '' && $v !== []);
    }

    /**
     * Las opciones, AGRUPADAS POR EJE y recortadas.
     *
     * ⚠️ La primera versión devolvía la lista plana: 66 habitaciones y 23 localizadores en un
     * mensaje de error. Es media ventana de contexto gastada en decir «te equivocaste de nombre»,
     * y encima ilegible. Agrupado —«Habitación: HA01, HA02… (66)»— el modelo ve la FORMA de lo que
     * hay, que es lo único que necesita para preguntar bien.
     *
     * @return list<array<string, mixed>>
     */
    private function opcionesDeSubgrupo(CotizacionFile $file): array
    {
        /** @var array<string, list<string>> $porEje */
        $porEje = [];
        foreach ($file->getGrupos() as $g) {
            if ($g->getTipo() === GrupoTipoEnum::SERVICIO) {
                continue;
            }
            $porEje[$g->getEtiquetaDeEje()][] = trim((string) $g->getClave().' '.($g->getNombre() ?? ''));
        }

        $salida = [];
        foreach ($porEje as $eje => $valores) {
            sort($valores);
            $salida[] = array_filter([
                'eje' => $eje,
                'total' => count($valores),
                'valores' => array_slice($valores, 0, self::MAX_OPCIONES_POR_EJE),
                'y_mas' => count($valores) > self::MAX_OPCIONES_POR_EJE
                    ? count($valores) - self::MAX_OPCIONES_POR_EJE
                    : null,
            ], static fn (mixed $v): bool => $v !== null);
        }

        return $salida;
    }

    /** @return list<string> */
    private function opcionesDeServicio(CotizacionFile $file): array
    {
        $opciones = [];
        foreach ($file->getGrupos() as $g) {
            if ($g->getTipo() === GrupoTipoEnum::SERVICIO) {
                $opciones[] = (string) $g->getClave();
            }
        }
        sort($opciones);

        return $opciones;
    }

    /** @param list<CotizacionFileGrupo>|null $grupos */
    private function describirFiltro(
        string $persona,
        ?array $grupos,
        ?CotizacionFileGrupo $servicio,
        bool $negar,
        ?PasajeroTipoEnum $rol,
        string $documentos,
    ): string {
        $partes = [];

        if ($persona !== '') {
            $partes[] = 'persona «'.$persona.'»';
        }

        if ($grupos !== null) {
            $partes[] = 'en '.implode(' o ', array_map(
                static fn (CotizacionFileGrupo $g): string => trim($g->getEtiquetaDeEje().' '.$g->getClave()),
                $grupos,
            ));
        }

        if ($servicio !== null) {
            $partes[] = ($negar ? 'SIN ' : 'con ').$servicio->getClave();
        }

        if ($rol !== null) {
            $partes[] = 'rol '.$rol->label();
        }

        if ($documentos !== '') {
            $partes[] = 'documentos '.$documentos;
        }

        return $partes === [] ? 'todos' : implode(' · ', $partes);
    }
}
