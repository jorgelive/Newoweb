<?php

declare(strict_types=1);

namespace App\Tests\Agent\Conversation;

use App\Agent\Access\ActorInterface;
use App\Agent\Access\NivelRiesgo;
use App\Agent\Conversation\AclaracionDeEmpate;
use App\Agent\Skill\SkillDefinition;
use App\Agent\Skill\SkillInterface;
use App\Agent\Skill\SkillResult;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Las dos guardas del empate de herramientas.
 *
 * El caso que las justifica es real y está fechado: el 18/08/2026 un «¿cuentan con agua
 * caliente para 5 personas?» empató dos skills de LECTURA, el agente preguntó «¿cuál de los
 * dos?» y la pregunta iba compuesta con las fichas internas de las herramientas. Los dos
 * primeros tests son ese mensaje, uno por cada cosa que salió mal.
 *
 * Unitario puro: skills anónimas, sin contenedor ni base de datos.
 */
final class AclaracionDeEmpateTest extends TestCase
{
    /** @return list<SkillInterface> */
    private function skills(NivelRiesgo ...$riesgos): array
    {
        $nombres = ['consultar_guia', 'consultar_conocimiento', 'registrar_pago'];
        $skills = [];

        foreach ($riesgos as $i => $riesgo) {
            $skills[] = new class ($nombres[$i], $riesgo) implements SkillInterface {
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

        return $skills;
    }

    #[Test]
    public function dos_lecturas_no_obligan_a_preguntar(): void
    {
        // EL CASO DE PRODUCCIÓN. Da igual que el empate fuera real: de cuál de las dos fuentes
        // sale la respuesta es decisión nuestra, y quien pregunta no puede contestarla.
        self::assertFalse(AclaracionDeEmpate::obliga(
            $this->skills(NivelRiesgo::Lectura, NivelRiesgo::Lectura)
        ));
    }

    #[Test]
    public function una_escritura_entre_las_empatadas_obliga_a_preguntar(): void
    {
        self::assertTrue(AclaracionDeEmpate::obliga(
            $this->skills(NivelRiesgo::Lectura, NivelRiesgo::Escritura)
        ));
    }

    #[Test]
    public function la_escritura_hacia_dentro_cuenta_como_lectura(): void
    {
        // `Interna` escribe —avisa al equipo, marca la conversación— y aun así no obliga: el
        // daño de un aviso de más es que alguien mire un chat que no hacía falta.
        self::assertFalse(AclaracionDeEmpate::obliga(
            $this->skills(NivelRiesgo::Lectura, NivelRiesgo::Interna)
        ));
    }

    #[Test]
    public function una_sola_candidata_no_es_un_empate(): void
    {
        self::assertFalse(AclaracionDeEmpate::obliga($this->skills(NivelRiesgo::Escritura)));
        self::assertFalse(AclaracionDeEmpate::obliga([]));
    }

    #[Test]
    public function se_descarta_lo_que_nombra_la_herramienta(): void
    {
        // LA OTRA MITAD DEL CASO DE PRODUCCIÓN: el texto que se envió llevaba dentro las fichas
        // internas. El nombre técnico es la señal barata de que ha pasado eso otra vez.
        $motivo = AclaracionDeEmpate::motivoDeDescarte(
            '¿Quieres que mire consultar_guia o el conocimiento general?',
            $this->skills(NivelRiesgo::Lectura, NivelRiesgo::Escritura)
        );

        self::assertNotNull($motivo);
        self::assertStringContainsString('consultar_guia', $motivo);
    }

    #[Test]
    public function se_descarta_lo_que_no_cabe_en_un_chat(): void
    {
        $motivo = AclaracionDeEmpate::motivoDeDescarte(
            str_repeat('a', AclaracionDeEmpate::MAX_CARACTERES + 1),
            $this->skills(NivelRiesgo::Escritura, NivelRiesgo::Escritura)
        );

        self::assertNotNull($motivo);
        self::assertStringContainsString('tope', $motivo);
    }

    #[Test]
    public function se_descarta_el_vacio_y_el_nulo(): void
    {
        $skills = $this->skills(NivelRiesgo::Escritura, NivelRiesgo::Escritura);

        self::assertNotNull(AclaracionDeEmpate::motivoDeDescarte(null, $skills));
        self::assertNotNull(AclaracionDeEmpate::motivoDeDescarte("  \n ", $skills));
    }

    #[Test]
    public function una_pregunta_en_lenguaje_llano_pasa(): void
    {
        self::assertNull(AclaracionDeEmpate::motivoDeDescarte(
            '¿Te cambio la fecha de salida, o sólo quieres saber hasta qué hora puedes quedarte?',
            $this->skills(NivelRiesgo::Lectura, NivelRiesgo::Escritura)
        ));
    }
}
