<?php

declare(strict_types=1);

/**
 * El prestador: qué se guarda, qué se sirve y qué no sale nunca.
 *
 * El componente guarda **campos planos** —enlace + nombre histórico— y la ficha (título,
 * descripción, url, imágenes) la inyecta el backend leyendo el catálogo al servir. Lo que
 * se comprueba:
 *
 *   1. Ninguna bandera cuelga de un prestador que no existe. Una bandera sobre un campo
 *      vacío no es inofensiva: si mañana alguien asigna el prestador, saldría publicado
 *      sin que nadie lo haya decidido.
 *   2. El gate, que desde el 27/08/2026 es UNO: `prestadorVisible` del componente. El
 *      global `proveedorOculto` se retiró —0 de 11 cotizaciones y no podía usarse en la
 *      dirección útil—, así que aquí ya no hay dos interruptores que combinar.
 *   3. **Lo servido está vivo**: sale del maestro, no de una copia. Se renombra de verdad
 *      —en transacción, con rollback— y se comprueba que cambia.
 *   4. La **descripción** viaja con el título y por el mismo gate (31/08/2026). Se
 *      comprueba con las dos superficies —empresa y servicio— porque el hueco que tapa es
 *      justo el de un campo que existe, se traduce y no lo lee nadie.
 *   5. Sin maestro no se inyecta nada: queda el nombre histórico y ninguna tarjeta a
 *      medias. Es la degradación buscada.
 *   6. La cara operativa no lleva el grupo público.
 *
 * ⚠️ Esta sonda estuvo rota desde el 19/08/2026 y no lo dijo nadie: seguía llamando a
 * `ProveedorVivoResolver` y consultando `travel_proveedor`, dos nombres que el renombrado a
 * `TravelOrganizacion` se llevó por delante. Una sonda que revienta al arrancar es peor que
 * ninguna, porque la doc la cita como si comprobara algo.
 *
 * Uso: php var/probar-prestador-visible.php
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use App\Cotizacion\Entity\CotizacionCotcomponente;
use App\Cotizacion\Serializer\CotizacionCotcomponentePrestadorPublicNormalizer;
use App\Cotizacion\Service\PrestadorVivoResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use Symfony\Component\Uid\Uuid;

(new Symfony\Component\Dotenv\Dotenv())->bootEnv(dirname(__DIR__, 2) . '/.env');
$_SERVER['APP_ENV'] = 'dev';
$kernel = new App\Kernel('dev', true);
$kernel->boot();

/** @var EntityManagerInterface $em */
$em = $kernel->getContainer()->get('doctrine')->getManager();
$conn = $em->getConnection();

// ── 1. Ninguna bandera sin prestador detrás ─────────────────────────────────
$huerfanas = (int) $conn->fetchOne(
    "SELECT COUNT(*) FROM cotizacion_cotcomponente
     WHERE prestador_visible = 1
       AND prestador_maestro_id IS NULL
       AND TRIM(COALESCE(prestador_nombre_snapshot, '')) = ''"
);

printf("1. Estado de las banderas\n");
printf("   componentes                   : %d\n", (int) $conn->fetchOne('SELECT COUNT(*) FROM cotizacion_cotcomponente'));
printf("   con prestador                 : %d\n", (int) $conn->fetchOne("SELECT COUNT(*) FROM cotizacion_cotcomponente WHERE prestador_maestro_id IS NOT NULL OR TRIM(COALESCE(prestador_nombre_snapshot,'')) <> ''"));
printf("   nombrables                    : %d\n", (int) $conn->fetchOne('SELECT COUNT(*) FROM cotizacion_cotcomponente WHERE prestador_visible = 1'));
printf("   banderas sin prestador detrás : %d  %s\n\n", $huerfanas, $huerfanas === 0 ? '✔' : '✘ REVISAR');

// ── Montaje: el decorado real es privado en dev, así que se envuelve un doble ───
$interior = new class implements NormalizerInterface {
    /** @return array<string, mixed> */
    public function normalize($object, ?string $format = null, array $context = []): array
    {
        assert($object instanceof CotizacionCotcomponente);

        // Lo que la entidad expone hoy al grupo público: nada del prestador. Los ids
        // dejaron de publicarse, así que ocultar es simplemente no inyectar.
        return ['id' => 'doble'];
    }

    public function supportsNormalization($data, ?string $format = null, array $context = []): bool
    {
        return true;
    }

    /** @return array<string, bool> */
    public function getSupportedTypes(?string $format): array
    {
        return ['*' => false];
    }
};

// Se busca una organización CON servicio, para poder probar las dos superficies.
$fila = $conn->fetchAssociative(
    'SELECT o.id AS org, s.id AS srv
       FROM travel_organizacion o
       JOIN travel_organizacion_servicio s ON s.organizacion_id = o.id
      WHERE JSON_LENGTH(o.titulo) > 0
      LIMIT 1'
);

if ($fila === false) {
    echo "No hay ninguna organización con título y servicio: no se puede probar la inyección.\n";
    exit(0);
}

$idOrg = strtolower((string) Uuid::fromBinary((string) $fila['org']));
$idSrv = strtolower((string) Uuid::fromBinary((string) $fila['srv']));

$c = new CotizacionCotcomponente();
$c->setPrestadorMaestroId($idOrg);
$c->setPrestadorServicioMaestroId($idSrv);
$c->setPrestadorNombreSnapshot('Nombre histórico guardado');
$c->setPrestadorVisible(true);

/** Sirve el componente con un resolver ya precargado. */
$servir = static function (PrestadorVivoResolver $r) use ($interior, $c): array {
    $out = (new CotizacionCotcomponentePrestadorPublicNormalizer($interior, $r))
        ->normalize($c, 'jsonld', ['groups' => ['pax_cotizacion:read']]);

    return is_array($out) ? $out : [];
};

/** Texto en español de un i18n servido. */
$es = static function (mixed $i18n): string {
    foreach ((array) $i18n as $n) {
        if (is_array($n) && ($n['language'] ?? '') === 'es') {
            return (string) ($n['content'] ?? '');
        }
    }

    return '';
};

$nuevoResolver = static function () use ($em, $idOrg, $idSrv): PrestadorVivoResolver {
    $r = new PrestadorVivoResolver($em);
    $r->precargar([$idOrg], [$idSrv]);

    return $r;
};

$conMaestro = $nuevoResolver();

// ── 2. El gate ──────────────────────────────────────────────────────────────
printf("2. Gate (se muestra ⟺ prestadorVisible; ya no hay flag global)\n");

foreach ([true, false] as $visible) {
    $c->setPrestadorVisible($visible);
    $d = $servir($conMaestro);
    $sale = isset($d['prestadorTitulo']);
    printf("   prestadorVisible=%-5s  sale=%-5s %s\n",
        var_export($visible, true), var_export($sale, true), $sale === $visible ? '✔' : '✘');
}

// ── 3 y 4. Lo servido está VIVO, y la descripción viaja con el título ───────
$c->setPrestadorVisible(true);
$conn->beginTransaction();

try {
    $org = $conMaestro->proveedor($idOrg);
    $srv = $conMaestro->servicio($idSrv);

    $org?->setTitulo([['language' => 'es', 'content' => 'ZZ Renombrado En Vivo']]);
    $org?->setDescripcion([['language' => 'es', 'content' => 'ZZ Prosa de la empresa']]);
    $srv?->setDescripcion([['language' => 'es', 'content' => 'ZZ Prosa del servicio']]);

    // La traducción automática se apaga: no se piden siete idiomas para una sonda que
    // termina en rollback. Misma precaución que en cualquier carga masiva.
    $org?->setEjecutarTraduccion(false);
    $srv?->setEjecutarTraduccion(false);
    $em->flush();

    $d = $servir($nuevoResolver());

    printf("\n3. Lo servido sale del catálogo, no de una copia\n");
    printf("   nombre histórico guardado : %s\n", (string) $c->getPrestadorNombreSnapshot());
    printf("   tras renombrar el maestro : %-24s %s\n", $es($d['prestadorTitulo'] ?? []),
        $es($d['prestadorTitulo'] ?? []) === 'ZZ Renombrado En Vivo' ? '✔ vivo' : '✘ sirvió una copia');

    printf("\n4. La descripción viaja con el título (31/08/2026)\n");
    printf("   prestadorDescripcion         : %-24s %s\n", $es($d['prestadorDescripcion'] ?? []),
        $es($d['prestadorDescripcion'] ?? []) === 'ZZ Prosa de la empresa' ? '✔' : '✘ no llega al cliente');
    printf("   prestadorServicioDescripcion : %-24s %s\n", $es($d['prestadorServicioDescripcion'] ?? []),
        $es($d['prestadorServicioDescripcion'] ?? []) === 'ZZ Prosa del servicio' ? '✔' : '✘ no llega al cliente');

    // Y con el prestador oculto no sale ninguna de las dos: describir la piscina
    // identifica al hotel igual que nombrarlo.
    $c->setPrestadorVisible(false);
    $oculto = $servir($nuevoResolver());
    $fuga = isset($oculto['prestadorDescripcion']) || isset($oculto['prestadorServicioDescripcion']);
    printf("   oculto ⇒ tampoco descripción : %-24s %s\n", $fuga ? 'FUGA' : 'no sale', $fuga ? '✘' : '✔');
    $c->setPrestadorVisible(true);
} finally {
    $conn->rollBack();
    $em->clear();
}

// ── 5. Sin maestro, degradación limpia ──────────────────────────────────────
$sinMaestro = $servir(new PrestadorVivoResolver($em));

printf("\n5. Si el maestro no existe\n");
printf("   no se inyecta ficha       : %-5s %s\n",
    var_export(!isset($sinMaestro['prestadorTitulo']), true),
    !isset($sinMaestro['prestadorTitulo']) ? '✔' : '✘ tarjeta a medias');
printf("   queda el nombre histórico : %-26s %s\n", (string) $c->getPrestadorNombreSnapshot(),
    $c->getPrestadorNombreSnapshot() !== null ? '✔' : '✘');

// ── 6. La cara operativa no sale ────────────────────────────────────────────
printf("\n6. Cara operativa sin grupo público (leído de los atributos)\n");
$refl = new ReflectionClass(CotizacionCotcomponente::class);

foreach (['prestadorNombreSnapshot', 'prestadorServicioNombreSnapshot', 'prestadorVisible', 'compradorMaestroId'] as $campo) {
    $grupos = [];
    foreach ($refl->getProperty($campo)->getAttributes(Groups::class) as $attr) {
        /** @var array<int, string|array<int, string>> $args */
        $args = $attr->getArguments();
        $grupos = array_merge($grupos, (array) ($args[0] ?? []));
    }

    $fuga = in_array('pax_cotizacion:read', $grupos, true);
    printf("   %-32s : %s  %s\n", $campo, $fuga ? 'FUGA' : 'sin grupo público', $fuga ? '✘' : '✔');
}
