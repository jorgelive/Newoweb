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

    public function __construct(private EntityManagerInterface $em) {}

    public function nombre(): string
    {
        return 'consultar_padron';
    }

    public function definicion(): SkillDefinition
    {
        return new SkillDefinition(
            descripcion: 'Consulta el padrón (name list) de un expediente de grupo y responde '
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

        $negar = (bool) ($entrada['negar_servicio'] ?? false);
        $rol = $this->rolPedido($entrada);
        $documentos = mb_strtolower(trim((string) ($entrada['documentos'] ?? '')));

        $cumplen = array_values(array_filter($gente, function (CotizacionFilepasajero $p) use (
            $grupos, $servicio, $negar, $rol, $documentos
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

            return $documentos === '' || in_array($documentos, $this->estadoDocumental($p), true);
        }));

        $salida = [
            'expediente' => $file->getNombreGrupo(),
            'localizador' => $file->getLocalizadorPublico(),
            'total_considerado' => count($gente),
            'cumplen' => count($cumplen),
            'filtro' => $this->describirFiltro($grupos, $servicio, $negar, $rol, $documentos),
        ];

        if (!$incluirNoParticipa) {
            $salida['nota'] = 'No se cuentan los «no participa».';
        }

        if ((bool) ($entrada['solo_conteo'] ?? false)) {
            return SkillResult::ok($salida);
        }

        usort($cumplen, static fn (CotizacionFilepasajero $a, CotizacionFilepasajero $b): int
            => strcmp((string) $a->getApellido(), (string) $b->getApellido()));

        $salida['personas'] = array_map($this->describirPersona(...), array_slice($cumplen, 0, self::MAX_NOMBRES));

        if (count($cumplen) > self::MAX_NOMBRES) {
            $salida['y_mas'] = count($cumplen) - self::MAX_NOMBRES;
            $salida['aviso'] = 'La lista va recortada. Di el total y ofrece acotar más.';
        }

        return SkillResult::ok($salida);
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
        $buscado = mb_strtolower($pedido);
        $encajan = [];

        foreach ($file->getGrupos() as $g) {
            if ($g->getTipo() === GrupoTipoEnum::SERVICIO) {
                continue;
            }

            $candidatos = [
                mb_strtolower((string) $g->getClave()),
                mb_strtolower((string) $g->getNombre()),
                mb_strtolower(trim($g->getEtiquetaDeEje().' '.$g->getClave())),
            ];

            if (in_array($buscado, array_filter($candidatos), true)) {
                $encajan[] = $g;
            }
        }

        return $encajan;
    }

    private function servicioQueEncaja(CotizacionFile $file, string $pedido): ?CotizacionFileGrupo
    {
        $buscado = mb_strtolower($pedido);

        foreach ($file->getGrupos() as $g) {
            if ($g->getTipo() === GrupoTipoEnum::SERVICIO && mb_strtolower((string) $g->getClave()) === $buscado) {
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
        $pedido = mb_strtolower(trim((string) ($entrada['rol'] ?? '')));

        if ($pedido === '') {
            return null;
        }

        foreach (PasajeroTipoEnum::cases() as $caso) {
            if ($caso->value === $pedido || mb_strtolower($caso->label()) === $pedido) {
                return $caso;
            }
        }

        return null;
    }

    /** @return array<string, mixed> */
    private function describirPersona(CotizacionFilepasajero $p): array
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

        $subgrupos = [];
        foreach ($p->grupos() as $g) {
            if ($g->getTipo() !== GrupoTipoEnum::SERVICIO) {
                $subgrupos[] = trim($g->getEtiquetaDeEje().' '.$g->getClave().' '.($g->getNombre() ?? ''));
            }
        }

        return array_filter([
            'nombre' => trim($p->getNombre().' '.$p->getApellido()),
            'rol' => $p->getTipo()?->label(),
            'edad' => $p->getEdad(),
            'telefono' => $p->getTelefono(),
            'documentos' => $documentos,
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
        ?array $grupos,
        ?CotizacionFileGrupo $servicio,
        bool $negar,
        ?PasajeroTipoEnum $rol,
        string $documentos,
    ): string {
        $partes = [];

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
