<?php

declare(strict_types=1);

namespace App\Tests\Cotizacion\Service;

use App\Cotizacion\Entity\CotizacionFile;
use App\Cotizacion\Entity\CotizacionFileGrupo;
use App\Cotizacion\Entity\CotizacionFilepasajero;
use App\Cotizacion\Entity\CotizacionPasajeroGrupo;
use App\Cotizacion\Entity\CotizacionPasajeroIdentificacion;
use App\Cotizacion\Entity\CotizacionVuelo;
use App\Cotizacion\Enum\GrupoTipoEnum;
use App\Cotizacion\Service\CargaMasivaDeArchivos;
use App\Enum\DocumentoTipoEnum;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use ZipArchive;

/**
 * El mismo número de vuelo dos veces en el expediente.
 *
 * ── Por qué existe este test ────────────────────────────────────────────────
 * `CotizacionVuelo` es único por `(file, numero, fecha)`, así que el JA7018 del 17 y el del 20 son
 * dos filas legítimas — un grupo grande sale en tandas y eso es lo normal, no la excepción.
 *
 * Lo que había antes tiraba el número al cubo en cuanto se repetía, y con él **el ZIP entero**:
 * mil boarding passes marcados con un error que además pedía algo imposible («añade la fecha al
 * nombre», cuando la fecha no participaba en ninguna búsqueda). La ambigüedad era GLOBAL y se
 * cobraba PERSONA A PERSONA, incluso en las que sólo vuelan ese número una vez.
 *
 * El desempate es el documento, que el nombre del fichero ya trae y que nunca es ambiguo.
 *
 * ⚠️ Ninguna prueba toca base de datos: las entidades son objetos planos y el grafo se arma a
 * mano, igual que lo hidrataría el ORM.
 */
final class CargaMasivaDesempateTest extends TestCase
{
    private string $projectDir;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir() . '/carga-masiva-' . bin2hex(random_bytes(6));
        mkdir($this->projectDir . '/var/documentos', 0o775, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->projectDir));
    }

    /**
     * Dos tandas del mismo grupo: el JA7018 vuela el 17 y el 20, con gente distinta cada día.
     *
     * 🔥 Es el caso que bloqueaba el ZIP entero. Cada persona vuela ese número UNA vez, así que no
     * hay ninguna duda real que resolver — y no hay que renombrar nada.
     */
    public function testDosTandasDelMismoNumeroSeResuelvenPorElPasajero(): void
    {
        $file = new CotizacionFile();

        $ida17 = $this->vuelo($file, 'JA 7018', '2026-09-17 07:15');
        $ida20 = $this->vuelo($file, 'JA 7018', '2026-09-20 07:15');

        $ana = $this->pasajero($file, 'Ana', '74003888', $this->grupo($file, 'RBEJRT', [$ida17]));
        $leo = $this->pasajero($file, 'Leo', '61919145', $this->grupo($file, 'X9SYVZ', [$ida20]));

        $plan = $this->planificar($file, ['74003888-JA7018.pdf', '61919145-JA7018.pdf']);

        self::assertNull($plan[0]['problema'], 'la primera tanda debería resolverse sola');
        self::assertSame($ana, $plan[0]['pasajero']);
        self::assertSame($ida17, $plan[0]['vuelo'], 'a Ana le toca el vuelo del 17');

        self::assertNull($plan[1]['problema'], 'la segunda tanda debería resolverse sola');
        self::assertSame($leo, $plan[1]['pasajero']);
        self::assertSame($ida20, $plan[1]['vuelo'], 'a Leo le toca el vuelo del 20');
    }

    /** Un solo vuelo con ese número: el camino de siempre, que no debe haberse roto. */
    public function testNumeroUnicoSigueCasando(): void
    {
        $file = new CotizacionFile();
        $vuelo = $this->vuelo($file, 'JA 7018', '2026-09-17 07:15');
        $this->pasajero($file, 'Ana', '74003888', $this->grupo($file, 'RBEJRT', [$vuelo]));

        $plan = $this->planificar($file, ['74003888-JA7018.pdf']);

        self::assertNull($plan[0]['problema']);
        self::assertSame($vuelo, $plan[0]['vuelo']);
    }

    /**
     * El número existe dos veces y la persona no vuela NINGUNO.
     *
     * Es el renombrado torcido —el DNI de uno con el vuelo de otro— y tiene que seguir cazándose
     * aunque ahora haya candidatos por medio.
     */
    public function testPersonaQueNoVuelaNingunoDeLosCandidatos(): void
    {
        $file = new CotizacionFile();
        $ida17 = $this->vuelo($file, 'JA 7018', '2026-09-17 07:15');
        $this->vuelo($file, 'JA 7018', '2026-09-20 07:15');

        // Vuela otra cosa: el JA7018 no es suyo ningún día.
        $otro = $this->vuelo($file, 'JA 7020', '2026-09-17 07:15');
        $this->pasajero($file, 'Ana', '74003888', $this->grupo($file, 'RBEJRT', [$otro]));

        $plan = $this->planificar($file, ['74003888-JA7018.pdf']);

        self::assertNotNull($plan[0]['problema']);
        self::assertStringContainsString('NO vuela', (string) $plan[0]['problema']);
        self::assertNull($plan[0]['vuelo'], 'no se elige a ciegas');
        self::assertNotSame($ida17, $plan[0]['vuelo']);
    }

    /**
     * 🔥 El caso límite de verdad: ir, volver y volver a ir.
     *
     * Un número de vuelo es una DIRECCIÓN —el JA7018 es CUZ→LIM—, así que para volarlo dos veces
     * hay que volver en medio. El nombre del fichero no puede resolverlo y el mensaje tiene que
     * mandar al formulario de uno en uno, que sí tiene selector de vuelo.
     *
     * ⚠️ Lo que se comprueba no es sólo que falle: es que el mensaje pida algo **cumplible**. El
     * anterior decía «añade la fecha al nombre» y eso no funcionaba de ninguna manera.
     */
    public function testLaMismaPersonaDosVecesAvisaYMandaAlFormulario(): void
    {
        $file = new CotizacionFile();
        $ida17 = $this->vuelo($file, 'JA 7018', '2026-09-17 07:15');
        $ida24 = $this->vuelo($file, 'JA 7018', '2026-09-24 07:15');

        $this->pasajero($file, 'Ana', '74003888', $this->grupo($file, 'RBEJRT', [$ida17, $ida24]));

        $plan = $this->planificar($file, ['74003888-JA7018.pdf']);

        $problema = (string) $plan[0]['problema'];

        self::assertNull($plan[0]['vuelo'], 'con dos suyos no se elige ninguno');
        self::assertStringContainsString('17/09', $problema, 'dice qué fechas chocan');
        self::assertStringContainsString('24/09', $problema);
        self::assertStringContainsString('eligiendo el vuelo', $problema, 'manda a donde sí se puede');
        self::assertStringNotContainsString('añade la fecha', $problema, 'ya no pide lo imposible');
    }

    /** Un número que no está en el expediente sigue diciendo lo suyo, no lo del caso límite. */
    public function testNumeroDesconocido(): void
    {
        $file = new CotizacionFile();
        $vuelo = $this->vuelo($file, 'JA 7018', '2026-09-17 07:15');
        $this->pasajero($file, 'Ana', '74003888', $this->grupo($file, 'RBEJRT', [$vuelo]));

        $plan = $this->planificar($file, ['74003888-XX9999.pdf']);

        self::assertSame('no se reconoce el número de vuelo', $plan[0]['problema']);
    }

    // ── Andamiaje ───────────────────────────────────────────────────────────

    /**
     * @param list<string> $nombres
     *
     * @return list<array{fichero: string, pasajero: ?CotizacionFilepasajero, vuelo: ?CotizacionVuelo, problema: ?string, ruta: ?string, reemplaza: bool}>
     */
    private function planificar(CotizacionFile $file, array $nombres): array
    {
        $rutaZip = $this->projectDir . '/entrada.zip';
        $zip = new ZipArchive();
        $zip->open($rutaZip, ZipArchive::CREATE);

        foreach ($nombres as $nombre) {
            $zip->addFromString($nombre, '%PDF-1.4 fingido');
        }

        $zip->close();

        // Un stub y no un mock: `planificar()` no escribe, así que no hay nada que esperar del
        // EntityManager. Es justamente lo que separa el paso de previsualizar del de guardar.
        $servicio = new CargaMasivaDeArchivos(
            $this->projectDir,
            $this->createStub(EntityManagerInterface::class),
        );

        return $servicio->planificar($file, $rutaZip);
    }

    private function vuelo(CotizacionFile $file, string $numero, string $salida): CotizacionVuelo
    {
        $vuelo = (new CotizacionVuelo())
            ->setFile($file)
            ->setNumero($numero)
            ->setOrigen('CUZ')
            ->setDestino('LIM')
            ->setSalida(new DateTimeImmutable($salida));

        $vuelo->initializeId();
        $file->getVuelos()->add($vuelo);

        return $vuelo;
    }

    /**
     * ⚠️ El vuelo se mete en la colección del grupo a mano: `CotizacionVuelo::addGrupo()` no
     * mantiene el otro lado, y `vuelaEseVuelo()` lee justamente por ahí.
     *
     * @param list<CotizacionVuelo> $vuelos
     */
    private function grupo(CotizacionFile $file, string $clave, array $vuelos): CotizacionFileGrupo
    {
        $grupo = (new CotizacionFileGrupo())
            ->setTipo(GrupoTipoEnum::RESERVA_AEREA)
            ->setSubeje('Nacional')
            ->setClave($clave);

        $grupo->initializeId();

        foreach ($vuelos as $vuelo) {
            $grupo->getVuelos()->add($vuelo);
            $vuelo->addGrupo($grupo);
        }

        return $grupo;
    }

    private function pasajero(
        CotizacionFile $file,
        string $nombre,
        string $documento,
        CotizacionFileGrupo $grupo,
    ): CotizacionFilepasajero {
        $pax = (new CotizacionFilepasajero())->setNombre($nombre)->setApellido('Prueba');
        $pax->initializeId();

        $identificacion = (new CotizacionPasajeroIdentificacion())
            ->setTipo(DocumentoTipoEnum::DNI)
            ->setNumero($documento);
        $pax->addIdentificacion($identificacion);

        $pertenencia = (new CotizacionPasajeroGrupo())->setPasajero($pax)->setGrupo($grupo);
        $pax->addPertenencia($pertenencia);
        $grupo->addMiembro($pertenencia);

        $file->addFilepasajero($pax);

        return $pax;
    }
}
