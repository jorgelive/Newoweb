<?php

declare(strict_types=1);

namespace App\Agent\Skill\Travel;

use App\Entity\Maestro\MaestroMoneda;
use App\Travel\Entity\TravelComponente;
use App\Travel\Entity\TravelTarifa;
use App\Travel\Enum\TarifaCategoriaEnum;
use App\Travel\Enum\TarifaModalidadEnum;
use App\Travel\Enum\TarifaProcedenciaEnum;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Lo que comparten `crear_tarifa` y `modificar_tarifa`: volcar la entrada, validarla y
 * retratar el resultado.
 *
 * Las dos skills están separadas porque son dos intenciones distintas y el operador las dice
 * distinto. Pero las reglas de una tarifa son las mismas se esté creando o cambiando, y
 * escribirlas dos veces es garantizar que dentro de tres meses una acepte lo que la otra
 * rechaza. Vive aquí, y las skills sólo aportan su contrato y su confirmación.
 *
 * ⚠️ **Lo que el modelo elige se valida contra listas cerradas.** Modalidad, categoría,
 * procedencia y moneda salen de enums y del maestro; un valor inventado se rechaza **con la
 * lista de los válidos en el mensaje**. Un modelo se inventa identificadores con toda la
 * seguridad del mundo, y aquí se está fijando lo que se le va a cobrar a alguien.
 */
final readonly class TarifaDesdeEntrada
{
    public function __construct(
        private EntityManagerInterface $em,
    ) {}

    /**
     * Vuelca la entrada sobre la tarifa. Devuelve el motivo si algo no vale, o `null` si todo bien.
     *
     * ⚠️ **Lo que no viene NO se toca**, que es lo que permite «súbele el precio» sin que se
     * borren las condiciones. Y un 0 en un límite SÍ lo quita: es la única forma que tiene el
     * operador de decir «sin tope» dictando por voz.
     *
     * @param array<string, mixed> $entrada
     */
    public function aplicar(TravelTarifa $tarifa, array $entrada, bool $exigeNombre): ?string
    {
        if (isset($entrada['nombre']) && trim((string) $entrada['nombre']) !== '') {
            $tarifa->setNombreInterno(trim((string) $entrada['nombre']));
        }

        if (isset($entrada['precio']) && $entrada['precio'] !== '') {
            $precio = (float) $entrada['precio'];

            if ($precio < 0) {
                return 'Un precio negativo no es una tarifa. Si querías un descuento, va como cargo aparte.';
            }

            $tarifa->setMonto(number_format($precio, 2, '.', ''));
        }

        if (isset($entrada['moneda']) && trim((string) $entrada['moneda']) !== '') {
            $id = mb_strtoupper(trim((string) $entrada['moneda']));
            $moneda = $this->em->find(MaestroMoneda::class, $id);

            if ($moneda === null) {
                return sprintf('La moneda «%s» no existe. Las que hay son USD y PEN.', $id);
            }

            $tarifa->setMoneda($moneda);
        }

        foreach ([
            'modalidad' => [TarifaModalidadEnum::class, 'setModalidad'],
            'categoria' => [TarifaCategoriaEnum::class, 'setCategoria'],
            'procedencia' => [TarifaProcedenciaEnum::class, 'setProcedencia'],
        ] as $clave => [$enum, $setter]) {
            if (!isset($entrada[$clave]) || trim((string) $entrada[$clave]) === '') {
                continue;
            }

            $valor = mb_strtolower(trim((string) $entrada[$clave]));
            $caso = $enum::tryFrom($valor);

            if ($caso === null) {
                return sprintf(
                    'No existe la %s «%s». Las válidas son: %s.',
                    $clave,
                    $valor,
                    implode(', ', array_column($enum::cases(), 'value'))
                );
            }

            $tarifa->{$setter}($caso);
        }

        foreach ([
            'edad_minima' => 'setEdadMinima',
            'edad_maxima' => 'setEdadMaxima',
            'capacidad_minima' => 'setCapacidadMinima',
            'capacidad_maxima' => 'setCapacidadMaxima',
        ] as $clave => $setter) {
            if (!array_key_exists($clave, $entrada) || $entrada[$clave] === '' || $entrada[$clave] === null) {
                continue;
            }

            $n = (int) $entrada[$clave];

            if ($n < 0) {
                return sprintf('«%s» no puede ser negativo.', $clave);
            }

            $tarifa->{$setter}($n === 0 ? null : $n);
        }

        if ($exigeNombre && trim((string) $tarifa->getNombreInterno()) === '') {
            return 'Una tarifa nueva necesita nombre: es lo que distingue «Adulto extranjero» de «Niño».';
        }

        return $this->coherente($tarifa);
    }

    /** Los rangos al revés no los caza ningún tipo, y dejan una tarifa que no aplica nunca. */
    public function coherente(TravelTarifa $t): ?string
    {
        if ($t->getEdadMinima() !== null && $t->getEdadMaxima() !== null
            && $t->getEdadMinima() > $t->getEdadMaxima()) {
            return sprintf(
                'El rango de edades queda al revés (%d a %d): así no aplicaría a nadie.',
                $t->getEdadMinima(),
                $t->getEdadMaxima()
            );
        }

        if ($t->getCapacidadMinima() !== null && $t->getCapacidadMaxima() !== null
            && $t->getCapacidadMinima() > $t->getCapacidadMaxima()) {
            return sprintf(
                'La capacidad queda al revés (%d a %d): así no aplicaría a ningún grupo.',
                $t->getCapacidadMinima(),
                $t->getCapacidadMaxima()
            );
        }

        return null;
    }

    /**
     * La tarifa como se la enseñamos al operador, con los límites vacíos dichos en voz alta.
     *
     * @return array<string, mixed>
     */
    public function retrato(TravelTarifa $t): array
    {
        return array_filter([
            'tarifa_id' => $t->getId() !== null ? (string) $t->getId() : null,
            'componente' => $t->getComponente()?->getNombreInterno(),
            'nombre' => $t->getNombreInterno(),
            'precio' => $t->getMonto(),
            'moneda' => $t->getMoneda()?->getId(),
            'modalidad' => $t->getModalidad()->value ?? 'sin especificar',
            'categoria' => $t->getCategoria()->value ?? 'sin especificar',
            'procedencia' => $t->getProcedencia()->value ?? 'cualquiera',
            'edad_minima' => $t->getEdadMinima() ?? 'sin límite',
            'edad_maxima' => $t->getEdadMaxima() ?? 'sin límite',
            'capacidad_minima' => $t->getCapacidadMinima() ?? 'sin límite',
            'capacidad_maxima' => $t->getCapacidadMaxima() ?? 'sin límite',
        ], static fn ($v) => $v !== null);
    }

    public function buscarTarifa(string $id): ?TravelTarifa
    {
        return Uuid::isValid($id) ? $this->em->find(TravelTarifa::class, Uuid::fromString($id)) : null;
    }

    public function buscarComponente(string $nombre): ?TravelComponente
    {
        if (mb_strlen($nombre) < 3) {
            return null;
        }

        /** @var TravelComponente|null $c */
        $c = $this->em->getRepository(TravelComponente::class)
            ->createQueryBuilder('c')
            ->andWhere('c.nombreInterno LIKE :q')
            ->setParameter('q', '%' . $nombre . '%')
            ->orderBy('c.nombreInterno', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $c;
    }

    /**
     * Los parámetros que describen una tarifa, iguales en las dos skills.
     *
     * @return list<\App\Agent\Skill\SkillParameter>
     */
    public static function parametrosComunes(): array
    {
        return [
            \App\Agent\Skill\SkillParameter::texto('nombre', 'Nombre interno de la tarifa, el que '
                . 've el operador. Ej. «Adulto extranjero».', requerido: false),
            \App\Agent\Skill\SkillParameter::numero('precio', 'Importe. Sin símbolo de moneda.', requerido: false),
            \App\Agent\Skill\SkillParameter::texto('moneda', 'USD o PEN.', requerido: false),
            \App\Agent\Skill\SkillParameter::texto('modalidad', 'privado o compartido.', requerido: false),
            \App\Agent\Skill\SkillParameter::texto('categoria', 'estandar, economico, superior o premium.', requerido: false),
            \App\Agent\Skill\SkillParameter::texto('procedencia', 'nacional, extranjero o can.', requerido: false),
            \App\Agent\Skill\SkillParameter::entero('edad_minima', 'Edad mínima. 0 para quitar el límite.', requerido: false),
            \App\Agent\Skill\SkillParameter::entero('edad_maxima', 'Edad máxima. 0 para quitar el límite.', requerido: false),
            \App\Agent\Skill\SkillParameter::entero('capacidad_minima', 'Personas mínimas. 0 para quitar.', requerido: false),
            \App\Agent\Skill\SkillParameter::entero('capacidad_maxima', 'Personas máximas. 0 para quitar.', requerido: false),
            \App\Agent\Skill\SkillParameter::booleano('confirmado', 'true SÓLO tras la aprobación '
                . 'explícita del operador. false para previsualizar.'),
        ];
    }
}
