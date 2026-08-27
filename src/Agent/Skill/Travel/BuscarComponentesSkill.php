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
use App\Security\Roles;
use App\Travel\Entity\TravelComponente;
use App\Travel\Enum\ComponenteTipoEnum;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Encuentra el COMPONENTE, que es de donde cuelga todo lo demás.
 *
 * ### Por qué hace falta, y no basta con `buscar_tarifas`
 *
 * Para tocar una tarifa hay que traer su `tarifa_id`, y para eso hay que dar con su componente.
 * `buscar_tarifas` ya lo hace **si aciertas el nombre**; el problema es cuando no se acierta:
 * hay 221 componentes y el operador dice «el bus a Puno» cuando en el catálogo pone «Transporte
 * turístico Cusco – Puno». Sin una forma de mirar el catálogo, la única salida era abrir el
 * panel — y entonces el agente no sirve para esto.
 *
 * Por eso esta skill busca **ancho**: por nombre, por tipo y por lugar. Devuelve
 * poco de cada componente —lo justo para reconocerlo y para encadenar— y **no trae las
 * tarifas**: para eso está la otra, y traerlas aquí llenaría la respuesta de precios que nadie
 * ha pedido.
 *
 * ### Lo que sí trae siempre: cuántas tarifas tiene
 *
 * Es el dato que decide el paso siguiente. Un componente con 0 tarifas no es un error de
 * búsqueda —es uno sin cargar—, y con 19 avisa de que la pregunta «¿cuánto cuesta?» va a
 * necesitar condiciones antes que un importe.
 */
final readonly class BuscarComponentesSkill implements SkillInterface, SkillDominioInterface
{
    private const int TOPE = 25;

    public function __construct(
        private EntityManagerInterface $em,
    ) {}

    public function nombre(): string
    {
        return 'buscar_componentes';
    }

    public function definicion(): SkillDefinition
    {
        return new SkillDefinition(
            descripcion: 'Encuentra un servicio del CATÁLOGO —un tour, un ticket, un traslado, '
                . 'un tren, una comida— para luego mirar o tocar sus tarifas. Es el primer paso '
                . 'cuando no sabes el nombre exacto: úsala para «¿qué transportes tenemos a '
                . 'Puno?», «busca el boleto de Machu Picchu», «¿qué servicios damos en el Valle '
                . 'Sagrado?», «¿qué tiene contratado el Ministerio de Cultura?». Busca a la vez '
                . 'por nombre o por lugar, y puedes acotar por tipo. Devuelve el '
                . 'componente_id y CUÁNTAS tarifas tiene cada uno: con ese id, buscar_tarifas te '
                . 'da los precios exactos. Si un componente sale con 0 tarifas no es que la '
                . 'búsqueda falle: es que no tiene precios cargados, y eso hay que decirlo. NO '
                . 'devuelve precios: para eso llama después a buscar_tarifas.',
            parametros: [
                SkillParameter::texto('busqueda', 'Lo que dijo el operador: parte del nombre del '
                    . 'servicio, un lugar («Puno», «Valle Sagrado») o una empresa. Basta un '
                    . 'trozo.', requerido: false),
                SkillParameter::texto('tipo', 'Acota por tipo: transporte, guiado, alojamiento, '
                    . 'ticket_fijo, ticket_variable, alimentacion_fijo, alimentacion_variable, '
                    . 'tren, vuelo, pool, privada, personal_extra, extras.', requerido: false),
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
        $tipoTexto = trim((string) ($entrada['tipo'] ?? ''));
        $tipo = null;

        if ($tipoTexto !== '') {
            $tipo = ComponenteTipoEnum::tryFrom(mb_strtolower($tipoTexto));

            if ($tipo === null) {
                return SkillResult::error(sprintf(
                    'No existe el tipo «%s». Los válidos son: %s.',
                    $tipoTexto,
                    implode(', ', array_column(ComponenteTipoEnum::cases(), 'value'))
                ));
            }
        }

        if ($busqueda === '' && $tipo === null) {
            return SkillResult::error(
                'Dime al menos qué buscas o de qué tipo: el catálogo tiene cientos de '
                . 'componentes y una lista entera no le sirve a nadie.'
            );
        }

        if ($busqueda !== '' && mb_strlen($busqueda) < 3) {
            return SkillResult::error('Con menos de tres letras salen demasiados. Dame un poco más.');
        }

        $qb = $this->em->getRepository(TravelComponente::class)
            ->createQueryBuilder('c')
            ->leftJoin('c.lugares', 'l')->addSelect('l')
            ->orderBy('c.nombreInterno', 'ASC')
            ->setMaxResults(self::TOPE + 1);

        if ($busqueda !== '') {
            // Sin empresa: el componente ya no fija prestador —sus tarifas pueden ser de
            // varias— así que sólo quedan el nombre y el lugar.
            $qb->andWhere('c.nombreInterno LIKE :q OR l.nombre LIKE :q')
                ->setParameter('q', '%' . $busqueda . '%');
        }

        if ($tipo !== null) {
            $qb->andWhere('c.tipo = :tipo')->setParameter('tipo', $tipo);
        }

        /** @var list<TravelComponente> $componentes */
        $componentes = $qb->getQuery()->getResult();
        $hayMas = count($componentes) > self::TOPE;
        $componentes = array_slice($componentes, 0, self::TOPE);

        if ($componentes === []) {
            return SkillResult::ok([
                'busqueda' => $busqueda !== '' ? $busqueda : null,
                'componentes' => [],
                'instruccion' => 'No hay ningún componente que encaje. NO te inventes uno: dile '
                    . 'que no lo encuentras y pregúntale de otra forma —por el lugar, por la '
                    . 'empresa, o por el tipo—.',
            ]);
        }

        return SkillResult::ok(array_filter([
            'busqueda' => $busqueda !== '' ? $busqueda : null,
            'tipo' => $tipo?->value,
            'componentes' => array_map(fn (TravelComponente $c) => $this->comoLinea($c), $componentes),
            'hay_mas' => $hayMas ? 'Hay más de los que caben: acota por tipo o por lugar.' : null,
            'instruccion' => 'Para los precios de uno de éstos, llama a buscar_tarifas con su '
                . 'nombre. Los que salgan con 0 tarifas no tienen precios cargados: dilo así.',
        ], static fn ($v) => $v !== null));
    }

    /** @return array<string, mixed> */
    private function comoLinea(TravelComponente $c): array
    {
        $lugares = [];

        foreach ($c->getLugares() as $lugar) {
            $nombre = $lugar->getNombre();

            if ($nombre !== null && $nombre !== '') {
                $lugares[] = $nombre;
            }
        }

        return array_filter([
            'componente_id' => (string) $c->getId(),
            'nombre' => $c->getNombreInterno(),
            'tipo' => $c->getTipo()->value,
            'duracion' => $c->getDuracion(),
            'lugares' => $lugares !== [] ? implode(', ', $lugares) : null,
            // El dato que decide el paso siguiente: 0 = sin precios cargados.
            'tarifas' => $c->getTarifas()->count(),
        ], static fn ($v) => $v !== null);
    }
}
