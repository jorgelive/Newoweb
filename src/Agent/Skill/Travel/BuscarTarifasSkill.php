<?php

declare(strict_types=1);

namespace App\Agent\Skill\Travel;

use App\Agent\Access\ActorInterface;
use App\Agent\Access\NivelRiesgo;
use App\Agent\Skill\SkillDefinition;
use App\Agent\Skill\SkillDominioInterface;
use App\Agent\Skill\SkillInterface;
use App\Agent\Skill\SkillParameter;
use App\Agent\Skill\SkillResult;
use App\Travel\Entity\TravelComponente;
use App\Travel\Entity\TravelTarifa;
use App\Security\Roles;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Las tarifas del catálogo maestro, con sus restricciones y su prestador.
 *
 * ### Qué problema resuelve
 *
 * Un componente llega a tener 19 tarifas —privado y compartido, nacional y extranjero, por
 * rangos de edad y de capacidad— y hasta ahora había que abrir el panel para saber cuál manda.
 * La pregunta real del operador no es «dame las tarifas» sino «¿cuánto cuesta ESTO para DOS
 * extranjeros adultos?», y eso lo contesta el conjunto de restricciones, no el importe suelto.
 *
 * ⚠️ **Devuelve las restricciones SIEMPRE, aunque estén vacías.** Un importe sin condiciones al
 * lado invita a cotizar la tarifa de nacionales a un extranjero, y ése es el error que cuesta
 * dinero. Cuando un límite no está puesto se dice «sin límite», no se omite: omitirlo se lee
 * como que no aplica.
 *
 * ### Por qué no acota dominio
 *
 * `dominios()` devuelve lista vacía —sin acotar— porque el catálogo Travel es transversal:
 * guarda tours, tickets, traslados y también componentes de alojamiento. Hoy el único frente
 * declarado es `hotelero`, así que acotarla ahí la volvería invisible para lo demás. Ver
 * CLAUDE.md, «lista vacía = sin acotar».
 */
final readonly class BuscarTarifasSkill implements SkillInterface, SkillDominioInterface
{
    /** Tope de tarifas devueltas. Con más, el modelo deja de leerlas y empieza a resumirlas. */
    private const int TOPE = 40;

    public function __construct(
        private EntityManagerInterface $em,
    ) {}

    public function nombre(): string
    {
        return 'buscar_tarifas';
    }

    public function definicion(): SkillDefinition
    {
        return new SkillDefinition(
            descripcion: 'Las tarifas del CATÁLOGO de servicios y tours —no las de las casitas—, '
                . 'con su precio, sus restricciones y quién las presta. Úsala para «¿qué tarifas '
                . 'tiene el boleto de Machu Picchu?», «¿cuánto cuesta el city tour para '
                . 'extranjeros?», «¿qué precio tenemos con el Ministerio de Cultura?». Busca por '
                . 'nombre del componente, o por el del prestador si preguntan por una empresa. '
                . 'Cada tarifa vuelve con sus CONDICIONES —privado o compartido, nacional o '
                . 'extranjero, categoría, edades y capacidad— y eso es lo que hay que leer antes '
                . 'de decir un precio: dos tarifas del mismo servicio se distinguen por ahí, no '
                . 'por el importe. Si una condición no está puesta te la devuelvo como «sin '
                . 'límite», y eso significa que la tarifa vale para cualquiera en ese eje. Para '
                . 'los precios de alojamiento por noche usa consultar_tarifas.',
            parametros: [
                SkillParameter::texto('busqueda', 'Nombre del componente (ej. «Machu Picchu», '
                    . '«city tour») o del prestador (ej. «Ministerio de Cultura»). Basta un '
                    . 'trozo del nombre.'),
                SkillParameter::texto('procedencia', 'Filtra por «nacional», «extranjero» o '
                    . '«can». Úsalo sólo si lo dicen.', requerido: false),
                SkillParameter::entero('pax', 'Número de personas, para quedarse con las tarifas '
                    . 'cuya capacidad lo admite.', requerido: false),
            ],
        );
    }

    public function dominios(): array
    {
        return [];
    }

    public function rolesRequeridos(): array
    {
        return [Roles::OPERACIONES_SHOW];
    }

    public function nivelRiesgo(): NivelRiesgo
    {
        return NivelRiesgo::Lectura;
    }

    /** @param array<string, mixed> $entrada */
    public function ejecutar(array $entrada, ActorInterface $actor): SkillResult
    {
        $busqueda = trim((string) ($entrada['busqueda'] ?? ''));

        if (mb_strlen($busqueda) < 3) {
            return SkillResult::error(
                'Dime al menos tres letras del componente o del prestador: con menos salen '
                . 'cientos de tarifas y ninguna sirve.'
            );
        }

        $componentes = $this->componentesQueCoinciden($busqueda);

        if ($componentes === []) {
            return SkillResult::ok([
                'busqueda' => $busqueda,
                'componentes' => [],
                'instruccion' => 'No hay ningún componente ni prestador que se llame así. NO te '
                    . 'inventes un precio: dile que no lo encuentras y pregúntale por el nombre '
                    . 'exacto, o que lo busque en el catálogo.',
            ]);
        }

        $procedencia = trim((string) ($entrada['procedencia'] ?? '')) ?: null;
        $pax = isset($entrada['pax']) ? (int) $entrada['pax'] : null;

        $salida = [];
        $total = 0;

        foreach ($componentes as $componente) {
            $tarifas = [];

            foreach ($componente->getTarifas() as $tarifa) {
                if (!$this->pasaElFiltro($tarifa, $procedencia, $pax)) {
                    continue;
                }

                if ($total >= self::TOPE) {
                    break 2;
                }

                $tarifas[] = $this->comoLinea($tarifa);
                $total++;
            }

            if ($tarifas === []) {
                continue;
            }

            $prestador = $componente->getPrestador();

            $salida[] = array_filter([
                'componente' => $componente->getNombre(),
                'componente_id' => (string) $componente->getId(),
                'prestador' => $prestador?->getNombreComercial(),
                'prestador_servicio' => $componente->getPrestadorServicio()?->getNombre(),
                'tarifas' => $tarifas,
            ], static fn ($v) => $v !== null);
        }

        return SkillResult::ok(array_filter([
            'busqueda' => $busqueda,
            'componentes' => $salida,
            'hay_mas' => $total >= self::TOPE ? true : null,
            'instruccion' => $salida === []
                ? 'Hay componentes con ese nombre pero ninguna tarifa pasa los filtros que '
                    . 'pediste. Dilo así, y ofrece quitar el filtro.'
                : null,
        ], static fn ($v) => $v !== null));
    }

    /**
     * Componentes cuyo nombre —o el de su prestador— contiene la búsqueda.
     *
     * @return list<TravelComponente>
     */
    private function componentesQueCoinciden(string $busqueda): array
    {
        /** @var list<TravelComponente> $r */
        $r = $this->em->getRepository(TravelComponente::class)
            ->createQueryBuilder('c')
            ->leftJoin('c.prestador', 'p')
            ->addSelect('p')
            ->leftJoin('c.tarifas', 't')
            ->addSelect('t')
            ->andWhere('c.nombre LIKE :q OR p.nombreComercial LIKE :q')
            ->setParameter('q', '%' . $busqueda . '%')
            ->orderBy('c.nombre', 'ASC')
            ->setMaxResults(12)
            ->getQuery()
            ->getResult();

        return $r;
    }

    private function pasaElFiltro(TravelTarifa $t, ?string $procedencia, ?int $pax): bool
    {
        if ($procedencia !== null
            && $t->getProcedencia() !== null
            && $t->getProcedencia()->value !== mb_strtolower($procedencia)) {
            return false;
        }

        // Una capacidad sin poner NO excluye: significa «cualquiera».
        if ($pax !== null) {
            if ($t->getCapacidadMinima() !== null && $pax < $t->getCapacidadMinima()) {
                return false;
            }
            if ($t->getCapacidadMaxima() !== null && $pax > $t->getCapacidadMaxima()) {
                return false;
            }
        }

        return true;
    }

    /**
     * Una tarifa con sus condiciones al lado.
     *
     * ⚠️ Los límites que no están puestos viajan como «sin límite» y no se omiten. Omitirlos
     * se lee como que la tarifa no aplica en ese eje, cuando es justo lo contrario.
     *
     * @return array<string, mixed>
     */
    private function comoLinea(TravelTarifa $t): array
    {
        return array_filter([
            'tarifa_id' => (string) $t->getId(),
            'nombre' => $t->getNombreInterno(),
            'precio' => $t->getMonto(),
            'moneda' => $t->getMoneda()?->getId(),
            'por_grupo' => $t->isCostoPorGrupo() ? 'sí: el importe es del grupo entero, no por persona' : null,
            'modalidad' => $t->getModalidad()?->value,
            'categoria' => $t->getCategoria()?->value,
            'procedencia' => $t->getProcedencia()->value ?? 'cualquiera',
            'edades' => $this->rango($t->getEdadMinima(), $t->getEdadMaxima(), 'años'),
            'capacidad' => $this->rango($t->getCapacidadMinima(), $t->getCapacidadMaxima(), 'personas'),
            'rol' => $t->getRol()->value,
            'nombre_para_prestador' => $t->getNombreParaPrestador(),
        ], static fn ($v) => $v !== null);
    }

    private function rango(?int $min, ?int $max, string $unidad): string
    {
        return match (true) {
            $min !== null && $max !== null => sprintf('de %d a %d %s', $min, $max, $unidad),
            $min !== null => sprintf('desde %d %s', $min, $unidad),
            $max !== null => sprintf('hasta %d %s', $max, $unidad),
            default => 'sin límite',
        };
    }
}
