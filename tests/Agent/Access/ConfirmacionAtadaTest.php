<?php

declare(strict_types=1);

namespace App\Tests\Agent\Access;

use App\Agent\Access\ActorInterface;
use App\Agent\Access\EscrituraAntesDelFallo;
use App\Agent\Access\EscriturasDelTurno;
use App\Agent\Access\GuardiaDeSkills;
use App\Agent\Access\NivelRiesgo;
use App\Agent\Access\PrevisualizacionesDelTurno;
use App\Agent\Access\RestriccionCanal;
use App\Agent\Skill\SkillDefinition;
use App\Agent\Skill\SkillInterface;
use App\Agent\Skill\SkillResult;
use App\Contract\VinculoComercial;
use App\Entity\User;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;

/**
 * Lo que se confirma es lo que se enseñó.
 *
 * El 17/10/2026 Jorge aprobó «Casita 4 de 35,40 a 24,00» (ajuste `-11.40`); ese turno se perdió y
 * en el siguiente el modelo ejecutó OTRA cosa (`"24"` y sólo las libres) con `confirmado: true`.
 * Nada lo frenó: cada llamada era independiente. Ahora el guardia compara la previsualización de
 * ahora con la que se guardó al enseñarla. Ver docs/Mensajeria.md §11.
 */
final class ConfirmacionAtadaTest extends TestCase
{
    private GuardiaDeSkills $guardia;
    private PrevisualizacionesDelTurno $turno;
    private EscriturasDelTurno $escriturasDelTurno;
    /** @var object{escrituras: list<array<string, mixed>>} */
    private object $registro;

    protected function setUp(): void
    {
        $this->turno = new PrevisualizacionesDelTurno();
        $this->escriturasDelTurno = new EscriturasDelTurno();
        $this->guardia = new GuardiaDeSkills(new ArrayAdapter(), $this->turno, $this->escriturasDelTurno);
        $this->registro = new class { /** @var list<array<string, mixed>> */ public array $escrituras = []; };
    }

    #[Test]
    public function previsualizar_y_confirmar_lo_mismo_aplica(): void
    {
        $this->llamar(['casita' => 'Casita 4', 'ajuste' => '-11.40', 'confirmado' => false]);
        $r = $this->llamar(['casita' => 'Casita 4', 'ajuste' => '-11.40', 'confirmado' => true]);

        self::assertSame(true, $r->datos['aplicado']);
        self::assertCount(1, $this->registro->escrituras);
    }

    /**
     * Lo aplicado queda apuntado, para que un fallo posterior del motor en el mismo turno diga qué
     * quedó hecho en vez de dejar que se pida otra vez (23/10/2026). Lo previsualizado, no.
     */
    #[Test]
    public function lo_aplicado_queda_apuntado_y_lo_previsualizado_no(): void
    {
        $this->llamar(['casita' => 'Casita 4', 'ajuste' => '-11.40', 'confirmado' => false]);
        self::assertSame(0, $this->escriturasDelTurno->cuantas());

        $antes = $this->escriturasDelTurno->cuantas();
        $this->llamar(['casita' => 'Casita 4', 'ajuste' => '-11.40', 'confirmado' => true]);

        self::assertSame(['ajustar_falsa'], $this->escriturasDelTurno->desde($antes));

        $aviso = new EscrituraAntesDelFallo($this->escriturasDelTurno->desde($antes), new \RuntimeException('Google AI 402'));
        self::assertStringContainsString('Ya quedó hecho: ajustar_falsa', $aviso->getMessage());
        self::assertStringContainsString('No lo vuelvas a pedir', $aviso->getMessage());
    }

    /** «Casita 3» y «3» son la misma casita: la previsualización es la misma, se aplica. */
    #[Test]
    public function un_cambio_de_forma_que_no_cambia_lo_enseñado_aplica(): void
    {
        $this->llamar(['casita' => 'Casita 3', 'ajuste' => '-15', 'confirmado' => false]);
        $r = $this->llamar(['casita' => '3', 'ajuste' => '-15', 'confirmado' => true]);

        self::assertSame(true, $r->datos['aplicado']);
    }

    /** El caso del 17/10: se enseñó -11.40 y se intenta aplicar "24" con solo_libres. */
    #[Test]
    public function confirmar_otros_datos_no_aplica_y_devuelve_lo_de_ahora(): void
    {
        $this->llamar(['casita' => 'Casita 4', 'ajuste' => '-11.40', 'confirmado' => false]);
        $r = $this->llamar(['casita' => 'Casita 4', 'ajuste' => '24', 'solo_libres' => true, 'confirmado' => true]);

        self::assertSame(false, $r->datos['aplicado']);
        self::assertSame('confirmacion_no_coincide', $r->datos['motivo']);
        self::assertSame('24', $r->datos['ajuste'], 'devuelve la previsualización de AHORA para enseñarla');
        self::assertSame([], $this->registro->escrituras);
    }

    #[Test]
    public function confirmar_sin_haber_enseñado_nada_no_aplica(): void
    {
        $r = $this->llamar(['casita' => 'Casita 4', 'ajuste' => '-5', 'confirmado' => true]);

        self::assertSame('previsualizacion_caducada', $r->datos['motivo']);
        self::assertSame([], $this->registro->escrituras);
    }

    /** Tras rechazar, lo enseñado pasa a ser lo nuevo: un sí sobre ESO ya aplica. */
    #[Test]
    public function tras_el_rechazo_un_si_sobre_lo_nuevo_aplica(): void
    {
        $this->llamar(['casita' => 'Casita 4', 'ajuste' => '-5', 'confirmado' => true]);
        $r = $this->llamar(['casita' => 'Casita 4', 'ajuste' => '-5', 'confirmado' => true]);

        self::assertSame(true, $r->datos['aplicado']);
    }

    #[Test]
    public function un_si_vale_para_una_sola_escritura(): void
    {
        $this->llamar(['casita' => 'Casita 4', 'ajuste' => '-5', 'confirmado' => false]);
        $this->llamar(['casita' => 'Casita 4', 'ajuste' => '-5', 'confirmado' => true]);
        $r = $this->llamar(['casita' => 'Casita 4', 'ajuste' => '-5', 'confirmado' => true]);

        self::assertSame('previsualizacion_caducada', $r->datos['motivo']);
        self::assertCount(1, $this->registro->escrituras);
    }

    #[Test]
    public function otra_conversacion_no_hereda_la_previsualizacion(): void
    {
        $this->llamar(['casita' => 'Casita 4', 'ajuste' => '-5', 'confirmado' => false], conversacion: 'A');
        $r = $this->llamar(['casita' => 'Casita 4', 'ajuste' => '-5', 'confirmado' => true], conversacion: 'B');

        self::assertSame('previsualizacion_caducada', $r->datos['motivo']);
    }

    /**
     * El caso del 17/10/2026: un despliegue entre la previsualización y el «sí». Con la huella en
     * `cache.app`, `cache:clear` la borraba y había que confirmar dos veces. Un proceso nuevo —otra
     * guardia, otro adaptador— sobre la misma carpeta tiene que encontrarla.
     */
    #[Test]
    public function la_huella_sobrevive_a_un_proceso_nuevo(): void
    {
        $carpeta = sys_get_temp_dir() . '/guardia-' . bin2hex(random_bytes(4));
        $antes = new GuardiaDeSkills(new FilesystemAdapter('previsualizaciones', 0, $carpeta), new PrevisualizacionesDelTurno());
        $despues = new GuardiaDeSkills(new FilesystemAdapter('previsualizaciones', 0, $carpeta), new PrevisualizacionesDelTurno());
        $skill = $this->skill(NivelRiesgo::Escritura);
        $actor = $this->actor('A');

        $antes->ejecutar($skill, ['casita' => 'Casita 4', 'ajuste' => '-4', 'confirmado' => false], $actor);
        $r = $despues->ejecutar($skill, ['casita' => '4', 'ajuste' => '-4', 'confirmado' => true], $actor);

        self::assertSame(true, $r->datos['aplicado']);
        (new FilesystemAdapter('previsualizaciones', 0, $carpeta))->clear();
    }

    /** El modelo previsualiza y confirma en dos vueltas del MISMO turno: nadie lo ha visto. */
    #[Test]
    public function en_el_mismo_turno_no_se_aplica(): void
    {
        $this->llamar(['casita' => 'Casita 4', 'ajuste' => '-5', 'confirmado' => false]);
        $r = $this->llamar(['casita' => 'Casita 4', 'ajuste' => '-5', 'confirmado' => true], mismoTurno: true);

        self::assertSame('sin_aprobar_todavia', $r->datos['motivo']);
        self::assertSame([], $this->registro->escrituras);

        // Y en el turno siguiente, tras el sí del operador, sí.
        $r = $this->llamar(['casita' => 'Casita 4', 'ajuste' => '-5', 'confirmado' => true]);
        self::assertSame(true, $r->datos['aplicado']);
    }

    #[Test]
    public function una_lectura_pasa_tal_cual(): void
    {
        $r = $this->guardia->ejecutar($this->skill(NivelRiesgo::Lectura), ['confirmado' => true], $this->actor('A'));

        self::assertSame(true, $r->datos['aplicado']);
    }

    /**
     * Cada llamada es un turno nuevo —un mensaje del operador entre medias—, salvo que se diga.
     *
     * @param array<string, mixed> $entrada
     */
    private function llamar(array $entrada, string $conversacion = 'A', bool $mismoTurno = false): SkillResult
    {
        if (!$mismoTurno) {
            $this->turno->reset();
        }

        return $this->guardia->ejecutar($this->skill(NivelRiesgo::Escritura), $entrada, $this->actor($conversacion));
    }

    /** Una skill de escritura que, como las reales, normaliza lo que recibe al previsualizar. */
    private function skill(NivelRiesgo $riesgo): SkillInterface
    {
        return new class ($riesgo, $this->registro) implements SkillInterface {
            public function __construct(private readonly NivelRiesgo $riesgo, private readonly object $registro) {}
            public function nombre(): string { return 'ajustar_falsa'; }
            public function definicion(): SkillDefinition { return new SkillDefinition(descripcion: 'falsa'); }
            public function rolesRequeridos(): array { return []; }
            public function nivelRiesgo(): NivelRiesgo { return $this->riesgo; }

            public function ejecutar(array $entrada, ActorInterface $actor): SkillResult
            {
                $casita = 'Casita ' . preg_replace('/\D/', '', (string) ($entrada['casita'] ?? ''));
                $lo = ['casita' => $casita, 'ajuste' => $entrada['ajuste'] ?? null, 'solo_libres' => ($entrada['solo_libres'] ?? false) === true];

                if (($entrada['confirmado'] ?? false) !== true) {
                    return SkillResult::ok($lo + ['aplicado' => false, 'motivo' => 'falta_confirmacion']);
                }

                $this->registro->escrituras[] = $lo;

                return SkillResult::ok($lo + ['aplicado' => true]);
            }
        };
    }

    private function actor(string $conversacion): ActorInterface
    {
        return new class ($conversacion) implements ActorInterface {
            public function __construct(private readonly string $conversacion) {}
            public function roles(): array { return []; }
            public function origen(): string { return 'test'; }
            public function contextoTipo(): ?string { return null; }
            public function contextoId(): ?string { return null; }
            public function asuntosDelHilo(): array { return []; }
            public function conversacionId(): ?string { return $this->conversacion; }
            public function vinculo(): VinculoComercial { return VinculoComercial::Ninguno; }
            public function restriccion(): RestriccionCanal { return RestriccionCanal::Ninguna; }
            public function dominios(): array { return []; }
            public function esDelEquipo(): bool { return true; }
            public function esProspecto(): bool { return false; }
            public function esAcompanante(): bool { return false; }
            public function usuario(): ?User { return null; }
            public function tieneRol(string $rol): bool { return true; }
            public function tieneAlguno(array $roles): bool { return true; }
            public function etiqueta(): string { return 'test'; }
        };
    }
}
