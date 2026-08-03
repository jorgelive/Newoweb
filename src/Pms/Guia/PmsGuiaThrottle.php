<?php

declare(strict_types=1);

namespace App\Pms\Guia;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

/**
 * Frena la enumeración de localizadores en los endpoints públicos de la guía.
 *
 * EL PROBLEMA. El localizador es la credencial: quien lo tiene, ve la guía.
 * LocatorTrait lo genera con 6 caracteres sobre un alfabeto de 31, o sea
 * 31^6 ≈ 887 millones de combinaciones. Suena a mucho hasta que se cuenta el
 * espacio ÚTIL: al atacante no le hace falta adivinar un localizador concreto,
 * le vale cualquiera que exista. Con 5.000 reservas vivas hay un acierto cada
 * ~177.500 intentos, que a 100 peticiones por segundo es media hora. Y lo que
 * hay detrás de un acierto es el nombre del huésped, sus fechas y —dentro de
 * la ventana— los códigos de la puerta.
 *
 * LA RESPUESTA. Se cuentan solo los FALLOS por IP, no las peticiones. Un
 * huésped legítimo pide siempre el mismo localizador, que existe, así que
 * nunca suma; puede recargar su guía las veces que quiera. Un enumerador
 * produce casi solo fallos y se queda sin presupuesto enseguida. Es la
 * diferencia entre proteger el recurso y molestar a quien tiene derecho a él.
 *
 * Implementado sobre el pool de caché en vez de con symfony/rate-limiter para
 * no meter una dependencia nueva; la ventana fija es aproximada, pero contra
 * enumeración por fuerza bruta sobra. Si algún día entra ese componente, esta
 * clase es el único punto a sustituir.
 *
 * PENDIENTE: CotizacionFile expone su localizador igual (ver
 * CotizacionFilePublicProvider) y tiene exactamente el mismo problema. Este
 * throttle es reutilizable tal cual — solo hace falta llamarlo desde allí.
 */
final class PmsGuiaThrottle
{
    /** Fallos tolerados por IP dentro de la ventana antes de cortar. */
    private const MAX_FALLOS = 10;

    /** Duración de la ventana, en segundos. */
    private const VENTANA = 900; // 15 min

    public function __construct(
        private readonly CacheItemPoolInterface $cache,
        private readonly RequestStack $requestStack,
    ) {
    }

    /**
     * Corta la petición si la IP ya agotó su presupuesto de fallos.
     * Se llama ANTES de tocar la base de datos: un atacante bloqueado no debe
     * poder usar el endpoint ni siquiera como generador de carga de queries.
     */
    public function asegurarPresupuesto(): void
    {
        if ($this->contarFallos() >= self::MAX_FALLOS) {
            // Sin Retry-After con el valor exacto: decirle al atacante cuándo
            // vuelve a tener cupo le ahorra el trabajo de medirlo.
            throw new TooManyRequestsHttpException(
                self::VENTANA,
                'Demasiados intentos. Espera unos minutos.'
            );
        }
    }

    /**
     * Registra un localizador que no existe (o que no da acceso a nada).
     *
     * Se llama en TODOS los caminos que acaban en 404, no solo en "reserva
     * inexistente": si solo contara ese, un atacante podría distinguir
     * "localizador válido pero sin estancias visibles" de "localizador falso"
     * por el hecho de que uno consume presupuesto y el otro no, y usar esa
     * diferencia como oráculo.
     */
    public function registrarFallo(): void
    {
        $item = $this->cache->getItem($this->clave());

        $actual = $item->isHit() ? (int) $item->get() : 0;

        $item->set($actual + 1);

        // La ventana se fija en el PRIMER fallo y no se renueva con cada uno.
        // Si se renovara, quien siguiera intentando estiraría su propio castigo
        // indefinidamente, y el bloqueo pasaría de ser un freno a ser permanente.
        if (!$item->isHit()) {
            $item->expiresAfter(self::VENTANA);
        }

        $this->cache->save($item);
    }

    private function contarFallos(): int
    {
        $item = $this->cache->getItem($this->clave());

        return $item->isHit() ? (int) $item->get() : 0;
    }

    /**
     * La IP se guarda hasheada: la caché es un almacén operativo, no un
     * registro de quién visitó qué, y el contador funciona igual sin poder
     * recuperar la dirección original.
     */
    private function clave(): string
    {
        $ip = $this->requestStack->getCurrentRequest()?->getClientIp() ?? 'desconocida';

        return 'pms_guia_fallos_' . hash('xxh128', $ip);
    }
}
