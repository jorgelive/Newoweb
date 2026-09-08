<?php

declare(strict_types=1);

namespace App\Cotizacion\Controller\Publico;

use App\Cotizacion\Entity\CotizacionFilearchivo;
use App\Cotizacion\Service\Publico\IdentidadDelPasajero;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * Entrega un adjunto del expediente **sin que PHP lo lea**.
 *
 * ── El problema ─────────────────────────────────────────────────────────────
 * Hasta ahora los adjuntos vivían dentro de `public/` y se servían por URL directa, así que **la
 * URL era la llave**: quien la tuviera, la adivinara o la recibiera reenviada, entraba. Para 8
 * boletos sueltos era discutible; con lo que viene —escaneos de pasaporte y DNI de 133 personas,
 * cien de ellas menores, y ~1 000 boarding passes con nombre completo y PNR— deja de serlo.
 *
 * ── Por qué no sobrecarga el servidor ───────────────────────────────────────
 * 🔥 **PHP no toca un byte del fichero.** Comprueba quién pregunta y devuelve una **cabecera**;
 * el archivo lo lee y lo manda **nginx** con `sendfile`, que es lo que hace mejor:
 *
 * ```
 * 1. GET /archivo/{id}          → PHP: ¿es suyo?  ~2 ms, cuerpo vacío
 * 2. X-Accel-Redirect: /_privado/archivos/TOKEN_boleto.pdf
 * 3. nginx lee de disco y responde
 * ```
 *
 * Sin esto, 133 pasajeros bajando 8 boarding passes serían mil transferencias **a través de PHP**,
 * con un proceso de php-fpm ocupado durante cada una. Es la razón por la que la gente acaba
 * dejándolo todo en `public/`, y no hace falta.
 *
 * ⚠️ **La ruta interna de nginx tiene que llevar `internal;`.** Sin esa palabra, la carpeta
 * privada queda accesible a pelo y todo esto no sirve de nada. Es *la* línea:
 *
 * ```nginx
 * location /_privado/ { internal; alias /var/www/openperu.pe/var/documentos/; }
 * ```
 *
 * ── Quién puede ver qué ─────────────────────────────────────────────────────
 * | Quién | Qué ve |
 * |---|---|
 * | Operador con sesión del panel | todo |
 * | Pasajero identificado (documento + fecha de nacimiento) | **sólo lo suyo**, y sólo lo que se le puede devolver |
 * | Cualquier otro | 404 |
 *
 * ⚠️ **404 y no 403**, siempre. Un 403 confirma que ese archivo existe, y con ids correlativos —o
 * con uno filtrado— eso ya es información. El que no tiene permiso no debe poder distinguir «no
 * es tuyo» de «no existe».
 *
 * ⚠️ **Y hay adjuntos que NO se devuelven al pasajero ni siendo suyos**: el escaneo de su propio
 * pasaporte. Lo sube él y ahí se acaba — que se lo pueda volver a descargar sólo añade una vía por
 * la que ese fichero puede salir, sin darle nada que no tenga ya. Ver
 * {@see CotizacionFilearchivo::esDevolvibleAlPasajero()}.
 */
#[AsController]
final class ArchivoPrivadoController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly IdentidadDelPasajero $identidad,
        private readonly Security $security,
        #[Autowire(param: 'kernel.project_dir')]
        private readonly string $projectDir,
    ) {
    }

    #[Route(
        '/archivo/{id}',
        name: 'cotizacion_archivo_privado',
        requirements: ['id' => '[0-9a-f-]{36}'],
        methods: ['GET'],
    )]
    public function __invoke(string $id): Response
    {
        if (!Uuid::isValid($id)) {
            return new Response(status: Response::HTTP_NOT_FOUND);
        }

        $archivo = $this->em->getRepository(CotizacionFilearchivo::class)->find(Uuid::fromString($id));

        if ($archivo === null || ($archivo->getImageName() ?? '') === '') {
            return new Response(status: Response::HTTP_NOT_FOUND);
        }

        if (!$this->puedeVerlo($archivo)) {
            return new Response(status: Response::HTTP_NOT_FOUND);
        }

        $ruta = $this->projectDir . '/var/documentos/archivos/' . $archivo->getImageName();

        // ⚠️ Se comprueba en disco antes de delegar: si el fichero no está, nginx devolvería su
        // propio 404 —una página HTML de nginx en medio de la app— en vez de algo que se entienda.
        if (!is_file($ruta)) {
            return new Response(status: Response::HTTP_NOT_FOUND);
        }

        $respuesta = new Response();
        $respuesta->headers->set('X-Accel-Redirect', '/_privado/archivos/' . $archivo->getImageName());

        // ⚠️ **El `Content-Type` hay que ponerlo AQUÍ.** Symfony pone `text/html` por defecto y esa
        // cabecera gana sobre la que nginx deduciría de la extensión: el navegador recibía el PDF
        // correcto y lo pintaba **como texto**, una pantalla de símbolos. El fichero estaba bien
        // desde el primer momento; lo que faltaba era decir qué era.
        //
        // `mime_content_type()` lee la cabecera del fichero, unos bytes — no lo transfiere, así que
        // no rompe la razón de ser de todo esto.
        $respuesta->headers->set('Content-Type', $this->tipoMime($ruta));

        // El nombre con el que se guarda en el móvil. `inline` para que el boarding pass se ABRA
        // —en el gate no se navega por la carpeta de descargas— y el navegador ofrezca guardarlo.
        $respuesta->headers->set(
            'Content-Disposition',
            sprintf('inline; filename="%s"', $archivo->nombreParaDescarga()),
        );

        // ⚠️ **Privada, y sin guardar.** Un intermediario que cachee esto lo serviría a otro; y
        // `max-age` en el propio navegador es peor de lo que parece: este enlace se abre en el
        // móvil compartido de la familia —por eso existe «No soy yo»—, así que A abre su tarjeta,
        // sale, entra B, y el «atrás» le serviría la de A durante una hora **sin pasar por PHP**,
        // que es donde se comprueba de quién es.
        //
        // `no-cache` no prohíbe guardar: obliga a revalidar, así que el service worker de `pax`
        // sigue pudiendo conservarla para el aeropuerto sin señal — que es el caso que importa.
        $respuesta->headers->set('Cache-Control', 'private, no-cache');
        $respuesta->headers->set('Vary', 'Cookie');

        return $respuesta;
    }

    /**
     * El operador lo ve todo; el pasajero, sólo lo suyo y sólo lo devolvible.
     *
     * El orden importa: primero la sesión del panel, que no depende del expediente, y sólo después
     * la identidad del pasajero, que sí.
     */
    private function puedeVerlo(CotizacionFilearchivo $archivo): bool
    {
        if ($this->security->getUser() !== null) {
            return true;
        }

        $file = $archivo->getFile();
        $pasajero = $archivo->getPasajero();

        if ($file === null) {
            return false;
        }

        // ── Sin dueño: es del expediente entero ─────────────────────────────
        // 🔥 **Esto llegó a producción cerrado de más y rompió la portada entera.** «Sin dueño no
        // hay a quién devolvérselo» sonaba prudente y era falso: la entrada a Machu Picchu, el
        // tren y la confirmación de reserva NO tienen dueño y son justo lo que la portada lleva
        // años ofreciendo. Con el candado puesto, **todo adjunto de la portada daba 404 a todo
        // cliente** — y como el 404 es mudo por diseño, parecía que el archivo no existía.
        //
        // Lo que manda es el TIPO: `esPublico()` es la misma regla con la que
        // {@see \App\Cotizacion\Entity\CotizacionFile::getDocumentosParaCliente()} arma esa
        // lista, así que la lista y el permiso no pueden volver a discrepar.
        //
        // ⚠️ Y si el expediente exige identificarse, esto también: sería absurdo cerrar el
        // itinerario con documento y fecha de nacimiento y dejar los boletos abiertos al lado.
        if ($pasajero === null) {
            if ($archivo->getTipoArchivo()?->esPublico() !== true) {
                return false;
            }

            return !$file->isExigeIdentificacion() || $this->identidad->estaIdentificado($file);
        }

        if (!$archivo->esDevolvibleAlPasajero()) {
            return false;
        }

        return $this->identidad->pasajeroIdentificado($file)?->getId()?->equals($pasajero->getId() ?? Uuid::v4()) === true;
    }

    /**
     * Qué es el fichero, para que el navegador lo abra en vez de escupirlo.
     *
     * Se pregunta al fichero y no a la extensión: el nombre en disco lo pone un `Namer` y una
     * extensión se puede escribir mal, pero los primeros bytes de un PDF siempre dicen PDF.
     */
    private function tipoMime(string $ruta): string
    {
        $detectado = @mime_content_type($ruta);

        return is_string($detectado) && $detectado !== '' ? $detectado : 'application/octet-stream';
    }
}
