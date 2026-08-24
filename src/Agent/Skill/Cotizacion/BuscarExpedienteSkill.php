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
use App\Cotizacion\Enum\GrupoTipoEnum;
use App\Security\Roles;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Encuentra el expediente por su localizador o por el nombre del grupo.
 *
 * Es el paso previo de {@see ConsultarPadronSkill}: sin él, preguntar «quién está en el grupo 6»
 * obligaría al modelo a inventarse un identificador, y **un modelo se inventa identificadores con
 * toda la seguridad del mundo**. Aquí devuelve los que existen y el otro los valida contra ellos.
 *
 * ### Devuelve el MAPA, no sólo el expediente
 *
 * Junto a cada expediente van los ejes que tiene y sus valores —«Grupo: 1..9», «Vuelo Nacional:
 * JetSMART, Sky Airline»— y la lista de servicios. Es lo que permite que la siguiente pregunta se
 * conteste sin una tercera llamada, y sobre todo lo que hace que el modelo **pregunte bien**: con
 * la lista delante dice «¿el grupo 6 o la habitación 6?» en vez de elegir uno.
 *
 * ⚠️ Los valores van RECORTADOS. Un expediente de colegio tiene 66 habitaciones y 23
 * localizadores: volcarlos todos es la mitad del contexto gastada en algo que nadie preguntó. Van
 * los primeros y el total, que basta para orientar.
 */
final readonly class BuscarExpedienteSkill implements SkillInterface, SkillDominioInterface
{
    /** Más de esto no ayuda: se le pide al operador que acote. */
    private const int MAX_RESULTADOS = 6;

    /** Valores de ejemplo por eje. Con esto el modelo sabe QUÉ preguntar sin recibir 66 filas. */
    private const int MAX_VALORES_POR_EJE = 12;

    public function __construct(private EntityManagerInterface $em) {}

    public function nombre(): string
    {
        return 'buscar_expediente';
    }

    public function definicion(): SkillDefinition
    {
        return new SkillDefinition(
            descripcion: 'Encuentra un expediente de viaje (también llamado «file», «grupo» o '
                . '«promoción») por su LOCALIZADOR o por el nombre del grupo, y devuelve cuánta '
                . 'gente lleva y CÓMO ESTÁ ORGANIZADA: sus subgrupos (grupo, habitación, vuelos '
                . 'con su aerolínea) y los servicios contratados. Úsala SIEMPRE antes de '
                . 'consultar_padron: esa skill necesita el localizador exacto, y aquí es donde '
                . 'sale. Aguanta nombres a medias y sin acentos. Si devuelve varias '
                . 'coincidencias, pregunta al operador cuál antes de seguir: no elijas tú. La '
                . 'respuesta trae, por cada eje, algunos de sus valores y cuántos hay en total '
                . '—no todos—: úsalos para saber qué se puede preguntar y para desambiguar («¿el '
                . 'grupo 6 o la habitación 6?»). Si el expediente no está en modo grupo no tiene '
                . 'padrón y te lo diré en el aviso.',
            parametros: [
                SkillParameter::texto('busqueda', 'Localizador del expediente o parte del nombre '
                    . 'del grupo. Ejemplos: «5SRAJV», «Colegio San José», «Punta Cana».'),
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
        $busqueda = trim((string) ($entrada['busqueda'] ?? ''));

        if (mb_strlen($busqueda) < 3) {
            return SkillResult::error('Indica al menos 3 caracteres para buscar.');
        }

        /** @var list<CotizacionFile> $files */
        $files = $this->em->createQueryBuilder()
            ->select('f')
            ->from(CotizacionFile::class, 'f')
            ->where('LOWER(f.localizador) = :exacto OR LOWER(f.nombreGrupo) LIKE :parcial')
            ->setParameter('exacto', mb_strtolower($busqueda))
            ->setParameter('parcial', '%'.mb_strtolower($busqueda).'%')
            ->setMaxResults(self::MAX_RESULTADOS + 1)
            ->getQuery()
            ->getResult();

        if ($files === []) {
            return SkillResult::ok([
                'total' => 0,
                'expedientes' => [],
                'aviso' => sprintf('No encontré ningún expediente con «%s». Prueba con el '
                    .'localizador o con menos palabras del nombre.', $busqueda),
            ]);
        }

        $hayMas = count($files) > self::MAX_RESULTADOS;

        return SkillResult::ok(array_filter([
            'total' => count($files),
            'expedientes' => array_map($this->resumir(...), array_slice($files, 0, self::MAX_RESULTADOS)),
            'aviso' => $hayMas
                ? 'Hay más coincidencias de las que caben. Pide al operador que acote.'
                : null,
        ], static fn (mixed $v): bool => $v !== null));
    }

    /**
     * @return array<string, mixed>
     */
    private function resumir(CotizacionFile $file): array
    {
        $pasajeros = $file->getFilepasajeros();
        $noParticipan = 0;
        foreach ($pasajeros as $p) {
            if ($p->getTipo()?->value === 'no_participa') {
                ++$noParticipan;
            }
        }

        /** @var array<string, list<string>> $porEje */
        $porEje = [];
        $servicios = [];
        foreach ($file->getGrupos() as $grupo) {
            if ($grupo->getTipo() === GrupoTipoEnum::SERVICIO) {
                $servicios[] = (string) $grupo->getClave();
                continue;
            }
            $porEje[$grupo->getEtiquetaDeEje()][] = trim(
                (string) $grupo->getClave().' '.($grupo->getNombre() ?? '')
            );
        }

        $ejes = [];
        foreach ($porEje as $etiqueta => $valores) {
            $ejes[] = array_filter([
                'eje' => $etiqueta,
                'total' => count($valores),
                'valores' => array_slice($valores, 0, self::MAX_VALORES_POR_EJE),
                'y_mas' => count($valores) > self::MAX_VALORES_POR_EJE
                    ? count($valores) - self::MAX_VALORES_POR_EJE
                    : null,
            ], static fn (mixed $v): bool => $v !== null);
        }

        sort($servicios);

        return array_filter([
            'localizador' => $file->getLocalizadorPublico(),
            'nombre_grupo' => $file->getNombreGrupo(),
            'modo' => $file->getModo()->value,
            'personas' => count($pasajeros),
            'no_participan' => $noParticipan ?: null,
            'ejes' => $ejes,
            'servicios' => $servicios,
            'aviso' => $file->isUsaPadron()
                ? null
                : 'Este expediente NO está en modo grupo: no tiene padrón ni subgrupos.',
        ], static fn (mixed $v): bool => $v !== null && $v !== []);
    }
}
