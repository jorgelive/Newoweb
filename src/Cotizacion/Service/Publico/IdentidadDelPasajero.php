<?php

declare(strict_types=1);

namespace App\Cotizacion\Service\Publico;

use App\Cotizacion\Entity\CotizacionFile;
use App\Cotizacion\Entity\CotizacionFilepasajero;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Uid\Uuid;

/**
 * Quién dice ser quien está mirando un expediente de grupo, y si se le cree.
 *
 * ── Por qué existe ──────────────────────────────────────────────────────────
 * La propuesta **operativa** de un grupo lleva datos por persona —tu vuelo, tu código, tu
 * horario—, y el enlace del expediente lo tienen 133 familias. Lo comercial es el mismo documento
 * para todos y se queda abierto; la operativa es la única puerta cerrada.
 *
 * ⚠️ **Es formulario o nada.** Se descartó enseñar el itinerario común y esconder lo personal:
 * obliga a clasificar cada campo nuevo, y **olvidarse lo deja a la vista**. Un fallo que no da
 * error y que sólo se descubre cuando ya lo vio quien no debía. Con la puerta entera cerrada la
 * pregunta «¿este campo es personal?» deja de existir.
 *
 * ── Qué se pide, y por qué esas dos cosas ───────────────────────────────────
 * Documento **y** fecha de nacimiento. El número de documento circula —está en una reserva, en un
 * correo de la agencia, en una lista de un colegio—; la fecha de nacimiento no suele acompañarlo.
 * Ninguna de las dos es un secreto fuerte, y no pretende serlo: esto separa a los 133 del grupo
 * entre sí, no defiende de un atacante decidido.
 *
 * ⚠️ Por eso **el freno de intentos no es opcional**: sin él, un documento fijo y un barrido de
 * fechas encuentra a cualquiera en una tarde.
 *
 * ── La identidad vive en la SESIÓN, no en un token ──────────────────────────
 * `util` y `pax` comparten dominio de cookie y el firewall `main` es stateful, así que la sesión
 * ya llega hasta aquí sin montar nada. Un token en la URL se reenvía por WhatsApp sin querer —que
 * es exactamente el reenvío que esto viene a limitar—.
 *
 * ⚠️ **Una llave por expediente** (`pax_identificado.<fileId>`). Identificarse en un viaje no abre
 * el de al lado, y así el día que alguien comparta un enlace no arrastra la identidad con él.
 */
final readonly class IdentidadDelPasajero
{
    /** Cuántos intentos antes de cerrar, y por cuánto. */
    private const int INTENTOS_MAX = 8;
    private const int VENTANA_SEGUNDOS = 900;

    public function __construct(
        private EntityManagerInterface $em,
        private RequestStack $requestStack,
        private CacheItemPoolInterface $cache,
    ) {
    }

    /**
     * ¿Ya se identificó en ESTE expediente?
     *
     * Un expediente que no exige identificación responde que sí siempre: no hay puerta que abrir.
     */
    public function estaIdentificado(CotizacionFile $file): bool
    {
        if (!$file->isExigeIdentificacion()) {
            return true;
        }

        return $this->pasajeroIdentificado($file) !== null;
    }

    /**
     * El pasajero que se identificó, o `null`.
     *
     * ⚠️ Se relee de la base y **no se confía en la sesión más allá del id**: entre que alguien se
     * identificó y vuelve, su ficha pudo borrarse del padrón. Devolver un pasajero que ya no está
     * en el expediente sería enseñarle datos de un viaje del que lo sacaron.
     */
    public function pasajeroIdentificado(CotizacionFile $file): ?CotizacionFilepasajero
    {
        $sesion = $this->requestStack->getSession();
        $guardado = $sesion->get($this->llave($file));

        if (!is_string($guardado) || !Uuid::isValid($guardado)) {
            return null;
        }

        $pasajero = $this->em->getRepository(CotizacionFilepasajero::class)->find(Uuid::fromString($guardado));

        return $pasajero?->getFile()?->getId()?->equals($file->getId() ?? Uuid::v4()) === true
            ? $pasajero
            : null;
    }

    /**
     * Comprueba documento + fecha de nacimiento y, si cuadran, lo recuerda.
     *
     * @return CotizacionFilepasajero|null `null` tanto si no cuadra como si se agotaron los
     *                                     intentos. Quien llama distingue con {@see self::bloqueado()}.
     */
    public function identificar(CotizacionFile $file, string $documento, string $fechaNacimiento): ?CotizacionFilepasajero
    {
        if ($this->bloqueado($file)) {
            return null;
        }

        $buscado = self::normalizarDocumento($documento);
        $nacimiento = \DateTimeImmutable::createFromFormat('!Y-m-d', trim($fechaNacimiento));

        // ⚠️ `createFromFormat` devuelve `false`, no lanza. Sin esta rama, una fecha basura acaba
        // comparándose contra `false` y el fallo aparece lejos. Es la familia que destapó el
        // nivel 7 de PHPStan.
        if ($buscado === '' || $nacimiento === false) {
            $this->anotarIntento($file);

            return null;
        }

        foreach ($file->getFilepasajeros() as $pasajero) {
            if ($pasajero->getFechanacimiento()?->format('Y-m-d') !== $nacimiento->format('Y-m-d')) {
                continue;
            }

            foreach ($pasajero->getIdentificaciones() as $identificacion) {
                if (self::normalizarDocumento((string) $identificacion->getNumero()) !== $buscado) {
                    continue;
                }

                $this->requestStack->getSession()->set(
                    $this->llave($file),
                    $pasajero->getId()?->toRfc4122(),
                );
                $this->olvidarIntentos($file);

                return $pasajero;
            }
        }

        $this->anotarIntento($file);

        return null;
    }

    /** ¿Se agotaron los intentos desde esta IP para este expediente? */
    public function bloqueado(CotizacionFile $file): bool
    {
        $item = $this->cache->getItem($this->llaveDeIntentos($file));

        return is_int($item->get()) && $item->get() >= self::INTENTOS_MAX;
    }

    /**
     * ⚠️ **Por expediente Y por IP.** Sólo por IP castigaría a un colegio entero detrás de un NAT;
     * sólo por expediente, a las 133 familias por culpa de un curioso. La ventana no se renueva
     * con cada intento: se cuenta desde el primero, o un atacante constante la mantendría viva y
     * nunca podría volver nadie.
     */
    private function anotarIntento(CotizacionFile $file): void
    {
        $item = $this->cache->getItem($this->llaveDeIntentos($file));
        $previos = is_int($item->get()) ? $item->get() : 0;

        if ($previos === 0) {
            $item->expiresAfter(self::VENTANA_SEGUNDOS);
        }

        $this->cache->save($item->set($previos + 1));
    }

    private function olvidarIntentos(CotizacionFile $file): void
    {
        $this->cache->deleteItem($this->llaveDeIntentos($file));
    }

    private function llave(CotizacionFile $file): string
    {
        return 'pax_identificado.' . $file->getId()?->toRfc4122();
    }

    private function llaveDeIntentos(CotizacionFile $file): string
    {
        $ip = $this->requestStack->getCurrentRequest()?->getClientIp() ?? 'sin_ip';

        return 'pax_ident_intentos.' . md5(($file->getId()?->toRfc4122() ?? '') . '|' . $ip);
    }

    /**
     * ⚠️ La gente escribe su documento con puntos, guiones y espacios, y casi nunca dos veces
     * igual. Comparar en crudo convierte un formulario correcto en un «no eres tú».
     */
    private static function normalizarDocumento(string $valor): string
    {
        return strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $valor));
    }
}
