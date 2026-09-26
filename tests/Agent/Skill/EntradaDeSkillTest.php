<?php

declare(strict_types=1);

namespace App\Tests\Agent\Skill;

use App\Agent\Skill\EntradaDeSkill;
use App\Agent\Skill\SkillInterface;
use App\Agent\Skill\SkillParameter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Lo que el modelo manda a una skill, leído con tipo — y leído SÓLO por los nombres que la skill
 * declara en su `SkillDefinition`, que es lo que ve el modelo.
 */
#[CoversClass(EntradaDeSkill::class)]
final class EntradaDeSkillTest extends TestCase
{
    /** La semántica exacta del `(string) ($entrada['x'] ?? '')` al que sustituye… */
    public function testTextoEsElDelCastDeAntesConLosEscalares(): void
    {
        $e = new EntradaDeSkill(['a' => 'hola', 'n' => 12, 'f' => 1.5, 'si' => true, 'no' => false]);

        self::assertSame('hola', $e->texto('a'));
        self::assertSame('12', $e->texto('n'));
        self::assertSame('1.5', $e->texto('f'));
        self::assertSame('1', $e->texto('si'), '(string) true');
        self::assertSame('', $e->texto('no'), '(string) false');
        self::assertSame('', $e->texto('falta'));
        self::assertNull($e->textoONull('falta'));
    }

    /** …salvo en lo que estaba roto: un array ya no es la palabra «Array». */
    public function testUnArrayNoSeConvierteEnLaPalabraArray(): void
    {
        self::assertSame('', (new EntradaDeSkill(['nombre' => ['Ana', 'López']]))->texto('nombre'));
    }

    public function testBooleanoEnteroYDecimal(): void
    {
        $e = new EntradaDeSkill(['c' => 'false', 'd' => 'true', 'n' => '7', 'x' => '12abc', 'p' => '120.50']);

        self::assertFalse($e->booleano('c'));
        self::assertTrue($e->booleano('d'));
        self::assertFalse($e->booleano('falta'));
        self::assertTrue($e->booleano('falta', siNoViene: true));
        self::assertSame(7, $e->entero('n'));
        self::assertSame(12, $e->entero('x'), 'el cast de antes: el modelo escribe «2 adultos» en un integer');
        self::assertSame(120.5, $e->decimal('p'));
    }

    /**
     * 🔑 **Cada nombre que lee una skill tiene que estar en su definición.** Si una skill lee
     * `$e->texto('casitas')` y declara `casita`, el modelo nunca mandará lo que ella busca, y no hay
     * error: la skill contesta «indica la casita» para siempre. Este test es el que lo impide.
     */
    public function testCadaSkillLeeSoloLoQueDeclara(): void
    {
        $sinDeclarar = [];
        $comprobadas = 0;
        $sinDefinicionLegible = 0;
        $raiz = dirname(__DIR__, 3) . '/src/Agent/Skill';

        $iterador = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($raiz));
        foreach ($iterador as $archivo) {
            if (!$archivo instanceof \SplFileInfo || !str_ends_with($archivo->getFilename(), 'Skill.php')) {
                continue;
            }

            $codigo = (string) file_get_contents($archivo->getPathname());
            if (!preg_match('/namespace ([^;]+);/', $codigo, $ns) || !preg_match('/final (?:readonly )?class (\w+)/', $codigo, $cl)) {
                continue;
            }

            $clase = $ns[1] . '\\' . $cl[1];
            if (!class_exists($clase) || !is_subclass_of($clase, SkillInterface::class)) {
                continue;
            }

            /** @var SkillInterface $skill */
            $skill = (new ReflectionClass($clase))->newInstanceWithoutConstructor();
            try {
                $parametros = $skill->definicion()->parametros;
            } catch (\Error) {
                // Su definición se arma con servicios (las plantillas que hay en la base): no se
                // puede leer sin el contenedor. Se cuenta aparte para que no pase en vacío.
                ++$sinDefinicionLegible;
                continue;
            }
            ++$comprobadas;
            $declarados = array_map(static fn (SkillParameter $p): string => $p->nombre, $parametros);

            preg_match_all("/\\\$e->(?:texto|textoONull|booleano|entero|decimal|textos|objetos|tiene)\\('([a-z_]+)'/", $codigo, $leidos);
            foreach (array_unique($leidos[1]) as $nombre) {
                if (!in_array($nombre, $declarados, true)) {
                    $sinDeclarar[] = $cl[1] . ' lee «' . $nombre . '»';
                }
            }
        }

        self::assertGreaterThanOrEqual(25, $comprobadas, "Sólo se comprobaron $comprobadas skills ($sinDefinicionLegible sin definición legible): el test pasaría en vacío.");
        self::assertSame([], $sinDeclarar, 'Campos que la skill lee y el modelo no conoce: ' . implode(', ', $sinDeclarar));
    }

    /** Lo que la revisión encontró con la lectura estricta: un 0 inventado donde había un número. */
    public function testEnteroYDecimalConservanElCast(): void
    {
        $e = new EntradaDeSkill(['a' => '2 adultos', 'b' => 2.5, 'c' => '2.0', 'p' => '120,50', 'l' => [1]]);

        self::assertSame(2, $e->entero('a'));
        self::assertSame(2, $e->entero('b'));
        self::assertSame(2, $e->entero('c'));
        self::assertSame(120.0, $e->decimal('p'));
        self::assertSame(7, $e->entero('l', siNoViene: 7), 'un array es «no vino»');
    }

    /** Donde el vacío significa algo («canales» vacío = todos), una lista no puede pasar por vacío. */
    public function testNoEsTextoDistingueUnaListaDeUnVacio(): void
    {
        $e = new EntradaDeSkill(['c' => ['whatsapp_meta'], 'v' => '', 'n' => 3]);

        self::assertTrue($e->noEsTexto('c'));
        self::assertSame(['whatsapp_meta'], $e->textos('c'));
        self::assertFalse($e->noEsTexto('v'));
        self::assertFalse($e->noEsTexto('n'));
        self::assertFalse($e->noEsTexto('falta'));
    }
}
