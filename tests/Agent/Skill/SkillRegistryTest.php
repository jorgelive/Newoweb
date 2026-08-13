<?php

declare(strict_types=1);

namespace App\Tests\Agent\Skill;

use App\Agent\Access\ActorInterface;
use App\Agent\Access\NivelRiesgo;
use App\Agent\Access\RestriccionCanal;
use App\Agent\Skill\SkillConmutableInterface;
use App\Agent\Skill\SkillDefinition;
use App\Agent\Skill\SkillDominioInterface;
use App\Agent\Skill\SkillInterface;
use App\Agent\Skill\SkillRegistry;
use App\Agent\Skill\SkillResult;
use App\Entity\User;
use App\Message\Contract\VinculoComercial;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Qué herramientas se le ofrecen a quien pregunta.
 *
 * Es la pieza con más lógica condicional del agente y la que decide, en cada turno, qué puede
 * hacer el modelo. Un fallo aquí no da error: da un bot que «ya no sabe» hacer algo, o uno que
 * ofrece herramientas que van a morir contra el contexto equivocado.
 *
 * Unitario puro: skills anónimas y un actor de doble, sin contenedor ni base de datos.
 */
final class SkillRegistryTest extends TestCase
{
    /** @param list<string> $roles @param list<string>|null $dominios */
    private function skill(
        string $nombre,
        array $roles = [],
        ?array $dominios = null,
        ?bool $activa = null,
        NivelRiesgo $riesgo = NivelRiesgo::Lectura,
    ): SkillInterface {
        // Tres formas distintas según lo que declare: sin interfaces opcionales, con dominio,
        // o conmutable. Es la combinatoria que `paraActor()` distingue con `instanceof`.
        if ($activa !== null) {
            return new class ($nombre, $roles, $riesgo, $activa) extends SkillDoble implements SkillConmutableInterface {
                public function __construct(string $n, array $r, NivelRiesgo $ri, private readonly bool $activa)
                {
                    parent::__construct($n, $r, $ri);
                }

                public function estaActiva(): bool { return $this->activa; }
            };
        }

        if ($dominios !== null) {
            return new class ($nombre, $roles, $riesgo, $dominios) extends SkillDoble implements SkillDominioInterface {
                /** @param list<string> $dominios */
                public function __construct(string $n, array $r, NivelRiesgo $ri, private readonly array $dominios)
                {
                    parent::__construct($n, $r, $ri);
                }

                public function dominios(): array { return $this->dominios; }
            };
        }

        return new SkillDoble($nombre, $roles, $riesgo);
    }

    /** @param list<string> $roles @param list<string> $dominios */
    private function actor(array $roles = [], array $dominios = [], bool $equipo = false): ActorInterface
    {
        return new class ($roles, $dominios, $equipo) implements ActorInterface {
            /** @param list<string> $roles @param list<string> $dominios */
            public function __construct(
                private readonly array $roles,
                private readonly array $dominios,
                private readonly bool $equipo,
            ) {}

            public function roles(): array { return $this->roles; }
            public function origen(): string { return 'test'; }
            public function contextoTipo(): ?string { return null; }
            public function contextoId(): ?string { return null; }
            public function conversacionId(): ?string { return null; }
            public function vinculo(): VinculoComercial { return VinculoComercial::Ninguno; }
            public function restriccion(): RestriccionCanal { return RestriccionCanal::Ninguna; }
            public function dominios(): array { return $this->dominios; }
            public function esDelEquipo(): bool { return $this->equipo; }
            public function esProspecto(): bool { return false; }
            public function usuario(): ?User { return null; }
            public function etiqueta(): string { return 'doble'; }

            public function tieneAlguno(array $roles): bool
            {
                return $roles === [] || array_intersect($roles, $this->roles) !== [];
            }
        };
    }

    /** @param list<SkillInterface> $skills @return list<string> */
    private function catalogo(array $skills, ActorInterface $actor, bool $incluirEscritura = true): array
    {
        $registro = new SkillRegistry($skills);

        return array_values(array_map(
            static fn (SkillInterface $s): string => $s->nombre(),
            $registro->paraActor($actor, $incluirEscritura)
        ));
    }

    /**
     * ⚠️ La regla que más cuesta creerse: **los dos vacíos dicen «no acotes»**, y ninguno «no».
     *
     * Es deliberado. La alternativa —vacío = ningún negocio— convierte cualquier olvido en un
     * actor sin una sola herramienta, y eso no da error en ningún log: el agente simplemente
     * contesta que no puede ayudar.
     */
    #[Test]
    public function un_actor_sin_dominios_no_se_acota(): void
    {
        $skills = [$this->skill('de_hotel', dominios: ['hotelero'])];

        self::assertSame(['de_hotel'], $this->catalogo($skills, $this->actor()));
    }

    #[Test]
    public function una_skill_sin_dominio_es_transversal(): void
    {
        $skills = [$this->skill('escalar')];

        self::assertSame(['escalar'], $this->catalogo($skills, $this->actor(dominios: ['turistico'])));
    }

    /** Lo que el filtro existe para hacer: no ofrecer herramientas de otro negocio. */
    #[Test]
    public function un_actor_no_ve_las_skills_de_un_negocio_ajeno(): void
    {
        $skills = [
            $this->skill('de_hotel', dominios: ['hotelero']),
            $this->skill('de_tours', dominios: ['turistico']),
            $this->skill('transversal'),
        ];

        self::assertSame(
            ['de_tours', 'transversal'],
            $this->catalogo($skills, $this->actor(dominios: ['turistico']))
        );
    }

    /** Con acceso a los dos negocios se ve todo: la intersección basta con que no sea vacía. */
    #[Test]
    public function con_dos_dominios_se_ve_lo_de_los_dos(): void
    {
        $skills = [
            $this->skill('de_hotel', dominios: ['hotelero']),
            $this->skill('de_tours', dominios: ['turistico']),
        ];

        self::assertSame(
            ['de_hotel', 'de_tours'],
            $this->catalogo($skills, $this->actor(dominios: ['hotelero', 'turistico']))
        );
    }

    /**
     * El equipo se salta el filtro de dominio entero: quien atiende alojamiento y tours por el
     * mismo WhatsApp interno necesita las dos cajas, y qué puede tocar lo deciden sus roles.
     */
    #[Test]
    public function el_equipo_no_se_acota_por_dominio(): void
    {
        $skills = [$this->skill('de_hotel', dominios: ['hotelero'])];

        self::assertSame(
            ['de_hotel'],
            $this->catalogo($skills, $this->actor(dominios: ['turistico'], equipo: true))
        );
    }

    /**
     * El interruptor va ANTES que todo: una skill apagada no existe para nadie, ni siquiera
     * para el equipo, porque no es una cuestión de permisos — no puede funcionar.
     */
    #[Test]
    public function una_skill_apagada_no_existe_para_nadie(): void
    {
        $skills = [$this->skill('apagada', activa: false)];

        self::assertSame([], $this->catalogo($skills, $this->actor(equipo: true)));
        self::assertSame([], $this->catalogo($skills, $this->actor()));
    }

    /** El dominio recorta el catálogo; los roles siguen mandando sobre lo que queda. */
    #[Test]
    public function el_dominio_no_sustituye_a_los_roles(): void
    {
        $skills = [$this->skill('de_hotel', roles: ['ROLE_ADMIN'], dominios: ['hotelero'])];

        self::assertSame([], $this->catalogo($skills, $this->actor(dominios: ['hotelero'])));
        self::assertSame(
            ['de_hotel'],
            $this->catalogo($skills, $this->actor(roles: ['ROLE_ADMIN'], dominios: ['hotelero']))
        );
    }

    /**
     * `Interna` sobrevive al filtro de sólo-lectura a propósito: escribe —avisa al equipo— pero
     * sin ella el chat del huésped se queda sin la única herramienta que le sirve para pedir
     * ayuda. Ver `NivelRiesgo::exigePermisoDeEscritura()`.
     */
    #[Test]
    public function sin_escritura_sobrevive_lo_interno_pero_no_lo_que_escribe(): void
    {
        $skills = [
            $this->skill('leer', riesgo: NivelRiesgo::Lectura),
            $this->skill('avisar', riesgo: NivelRiesgo::Interna),
            $this->skill('guardar', riesgo: NivelRiesgo::Escritura),
        ];

        self::assertSame(
            ['leer', 'avisar'],
            $this->catalogo($skills, $this->actor(), incluirEscritura: false)
        );
    }
}

class SkillDoble implements SkillInterface
{
    /** @param list<string> $roles */
    public function __construct(
        private readonly string $nombre,
        private readonly array $roles,
        private readonly NivelRiesgo $riesgo,
    ) {}

    public function nombre(): string { return $this->nombre; }
    public function definicion(): SkillDefinition { return new SkillDefinition(descripcion: 'doble'); }
    public function ejecutar(array $entrada, ActorInterface $actor): SkillResult { return SkillResult::ok([]); }
    public function rolesRequeridos(): array { return $this->roles; }
    public function nivelRiesgo(): NivelRiesgo { return $this->riesgo; }
}
