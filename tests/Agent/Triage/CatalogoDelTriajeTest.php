<?php

declare(strict_types=1);

namespace App\Tests\Agent\Triage;

use App\Agent\Access\ActorInterface;
use App\Agent\Access\NivelRiesgo;
use App\Agent\Skill\SkillDefinition;
use App\Agent\Skill\SkillInterface;
use App\Agent\Skill\SkillResult;
use App\Agent\Triage\CatalogoDelTriaje;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Las dos listas blancas del triaje.
 *
 * ⚠️ **Estos tests existen por lo que NO cazaron los otros.** `AclaracionDeEmpateTest` prueba
 * la guarda del empate y sus ocho casos pasaban —y pasaban también el día que se descubrió que
 * la guarda no podía dispararse nunca, porque el triaje construía su catálogo sin las skills
 * de escritura y ningún candidato podía traer una—. Una guarda correcta a la que no llega nada
 * es código muerto que parece cobertura. Lo que se prueba aquí es lo otro: qué PUEDE llegar.
 *
 * Unitario puro: skills anónimas, sin contenedor ni base de datos.
 */
final class CatalogoDelTriajeTest extends TestCase
{
    private function skill(string $nombre, NivelRiesgo $riesgo): SkillInterface
    {
        return new class ($nombre, $riesgo) implements SkillInterface {
            public function __construct(
                private readonly string $nombre,
                private readonly NivelRiesgo $riesgo,
            ) {}

            public function nombre(): string { return $this->nombre; }
            public function definicion(): SkillDefinition { return new SkillDefinition('doble'); }
            public function ejecutar(array $entrada, ActorInterface $actor): SkillResult { return SkillResult::ok([]); }
            public function rolesRequeridos(): array { return []; }
            public function nivelRiesgo(): NivelRiesgo { return $this->riesgo; }
        };
    }

    /** @return list<SkillInterface> */
    private function catalogoDeOperador(): array
    {
        // El par real del catálogo de Reservas (RW): mirar y hacer, sobre lo mismo.
        return [
            $this->skill('evaluar_cambio_horario', NivelRiesgo::Lectura),
            $this->skill('aplicar_cambio_horario', NivelRiesgo::Escritura),
            $this->skill('escalar_al_equipo', NivelRiesgo::Interna),
        ];
    }

    #[Test]
    public function una_escritura_del_catalogo_puede_empatar(): void
    {
        // Que una escritura del catálogo sobreviva a la lista de candidatos. Es la mitad de la
        // condición; la otra —que el catálogo LLEGUE a tener escrituras— la prueba
        // `el_equipo_ve_las_escrituras_y_el_huesped_no`. Hacían falta las dos: el fallo original
        // cumplía ésta y no aquélla.
        self::assertContains(
            'aplicar_cambio_horario',
            CatalogoDelTriaje::permitidas($this->catalogoDeOperador())
        );
    }

    #[Test]
    public function una_escritura_no_se_enruta_directa(): void
    {
        $directas = CatalogoDelTriaje::enrutablesDirectas($this->catalogoDeOperador());

        self::assertNotContains('aplicar_cambio_horario', $directas);
        self::assertContains('evaluar_cambio_horario', $directas);
    }

    #[Test]
    public function la_escritura_hacia_dentro_si_se_enruta_directa(): void
    {
        // `Interna` escribe hacia dentro y cuenta como lectura en todo el sistema: es la que
        // permite que un huésped levante la mano. Ver NivelRiesgo::Interna.
        self::assertContains(
            'escalar_al_equipo',
            CatalogoDelTriaje::enrutablesDirectas($this->catalogoDeOperador())
        );
    }

    #[Test]
    public function el_catalogo_del_huesped_no_puede_obligar_a_preguntar(): void
    {
        // La garantía de que al huésped no se le pregunte NO es el prompt: es que en su
        // catálogo no hay una sola skill de escritura. Éste es el empate del 18/08/2026.
        $huesped = [
            $this->skill('consultar_guia', NivelRiesgo::Lectura),
            $this->skill('consultar_conocimiento', NivelRiesgo::Lectura),
        ];

        self::assertSame(
            CatalogoDelTriaje::permitidas($huesped),
            CatalogoDelTriaje::enrutablesDirectas($huesped)
        );
    }

    #[Test]
    public function el_equipo_ve_las_escrituras_y_el_huesped_no(): void
    {
        // 🔥 LA OTRA MITAD, y la que de verdad falló: esto era un `false` fijo en el triaje, así
        // que ningún candidato podía traer nunca una escritura por mucho que la lista blanca las
        // admitiera. Si alguien lo vuelve a fijar, este test cae.
        $equipo = $this->createStub(ActorInterface::class);
        $equipo->method('esDelEquipo')->willReturn(true);

        $huesped = $this->createStub(ActorInterface::class);
        $huesped->method('esDelEquipo')->willReturn(false);

        self::assertTrue(CatalogoDelTriaje::veEscrituras($equipo));
        self::assertFalse(CatalogoDelTriaje::veEscrituras($huesped));
    }

    #[Test]
    public function un_nombre_inventado_no_sobrevive(): void
    {
        self::assertSame(
            ['evaluar_cambio_horario'],
            CatalogoDelTriaje::candidatos(
                ['evaluar_cambio_horario', 'consultar_el_futuro'],
                CatalogoDelTriaje::permitidas($this->catalogoDeOperador())
            )
        );
    }

    #[Test]
    public function los_repetidos_no_fabrican_un_empate(): void
    {
        // Dos veces el mismo nombre no son dos candidatos: con `count() > 1` contando duplicados
        // se preguntaría por un empate que no existe.
        self::assertSame(
            ['evaluar_cambio_horario'],
            CatalogoDelTriaje::candidatos(
                ['evaluar_cambio_horario', ' evaluar_cambio_horario ', ''],
                CatalogoDelTriaje::permitidas($this->catalogoDeOperador())
            )
        );
    }
}
