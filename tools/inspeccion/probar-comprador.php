<?php

declare(strict_types=1);

/**
 * ¿El COMPRADOR resuelve bien, y sigue siendo invisible para el cliente?
 *
 * Es el tercer rol: el proveedor pone el precio, el prestador presta el servicio y el
 * comprador **ejecuta la compra**. Lo que se comprueba:
 *
 *   1. La cascada `componente → prestador`. Sin encargo explícito se le pide a quien
 *      presta, que es el caso normal — y por eso las cotizaciones anteriores al campo se
 *      comportan exactamente igual que antes.
 *   2. El caso Futurismo: contratas el Hotel Estelar a través de Futurismo porque consigue
 *      mejor precio. Prestador = el hotel, comprador = Futurismo. Es el que justifica el rol.
 *   3. **Siempre es una `TravelOrganizacion`**, también los internos: no hay un segundo catálogo de
 *      personas que mantener ni un «¿de qué clase es?» que responder antes de elegir.
 *   4. **No tiene cara pública.** Ninguno de sus campos lleva `pax_cotizacion:read`, y no
 *      hay bandera de visibilidad: a quién le encargaste la compra no es asunto del
 *      cliente, así que no debe existir la posibilidad de enseñárselo.
 *
 * Objetos en memoria; no toca la base.
 *
 * Uso: php var/probar-comprador.php
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use App\Cotizacion\Entity\CotizacionCotcomponente;
use Symfony\Component\Serializer\Annotation\Groups;

(new Symfony\Component\Dotenv\Dotenv())->bootEnv(dirname(__DIR__, 2) . '/.env');
$_SERVER['APP_ENV'] = 'dev';
$kernel = new App\Kernel('dev', true);
$kernel->boot();

echo "══ Comprador: a quién se le encarga ejecutar la compra ══\n\n";

// ── 1. Cascada: sin encargo, se le compra al proveedor ──────────────────────
$c = new CotizacionCotcomponente();
$c->setPrestadorMaestroId('019f4391-083b-7d5c-9dae-f51956fb288a');
$c->setPrestadorNombreSnapshot('Consorcio Cosituc');

$r = $c->resolverComprador();

printf("1. Sin encargo explícito → se le pide al prestador\n");
printf("   origen : %-12s %s\n", $r?->origen ?? 'null', $r?->origen === 'prestador' ? '✔' : '✘');
printf("   nombre : %-20s %s\n", $r?->nombre ?? 'null', $r?->nombre === 'Consorcio Cosituc' ? '✔' : '✘');
printf("   heredado : %-10s %s  (nadie encargó nada: se le compra al vendedor)\n\n",
    var_export($r?->esHeredado(), true), $r?->esHeredado() === true ? '✔' : '✘');

// ── 2. El caso que justificó el rol ─────────────────────────────────────────
$c->setPrestadorNombreSnapshot('Hotel Estelar');
$c->setCompradorMaestroId('a1b2c3d4-0000-4000-8000-000000000001');
$c->setCompradorNombreSnapshot('Futurismo');

$r = $c->resolverComprador();

printf("2. El caso Futurismo: el hotel presta, Futurismo compra\n");
printf("   prestador (quién presta)          : %s\n", (string) $c->getPrestadorNombreSnapshot());
printf("   comprador (a quién se le encarga) : %s\n", $r?->nombre ?? 'null');
printf("   se separan                        : %s  %s\n",
    var_export($r?->nombre !== $c->getPrestadorNombreSnapshot(), true),
    $r?->nombre !== $c->getPrestadorNombreSnapshot() ? '✔' : '✘');
printf("   origen                            : %-12s %s\n\n",
    $r?->origen ?? 'null', $r?->origen === 'componente' ? '✔' : '✘');

// ── 3. Los dos tipos apuntan a catálogos distintos ──────────────────────────
printf("3. Siempre una empresa del catálogo: un solo sitio de donde elegir\n");
$sinTipo = !(new ReflectionClass(CotizacionCotcomponente::class))->hasProperty('compradorTipo');
printf("   sin columna de tipo   : %s  %s\n", var_export($sinTipo, true), $sinTipo ? '✔' : '✘');
printf("   el catálogo existe    : %s  %s\n",
    App\Travel\Entity\TravelOrganizacion::class, class_exists(App\Travel\Entity\TravelOrganizacion::class) ? '✔' : '✘');

// ── 4. Sin cara pública ─────────────────────────────────────────────────────
printf("\n4. El cliente no lo ve NUNCA (leído de los atributos)\n");
$refl = new ReflectionClass(CotizacionCotcomponente::class);
foreach (['compradorMaestroId', 'compradorNombreSnapshot'] as $campo) {
    $grupos = [];
    foreach ($refl->getProperty($campo)->getAttributes(Groups::class) as $attr) {
        /** @var array<int, string|array<int, string>> $args */
        $args = $attr->getArguments();
        $grupos = array_merge($grupos, (array) ($args[0] ?? []));
    }
    $fuga = in_array('pax_cotizacion:read', $grupos, true);
    printf("   %-26s : %s  %s\n", $campo, $fuga ? 'FUGA' : 'sin grupo público', $fuga ? '✘' : '✔');
}

// Y que no exista una bandera de visibilidad: el rol no debe poder mostrarse.
$tieneBandera = $refl->hasProperty('compradorVisible');
printf("   sin bandera de visibilidad : %s  %s\n",
    var_export(!$tieneBandera, true), $tieneBandera ? '✘ no debería existir' : '✔');
