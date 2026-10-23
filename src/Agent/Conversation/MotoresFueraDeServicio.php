<?php

declare(strict_types=1);

namespace App\Agent\Conversation;

use Psr\Cache\CacheItemPoolInterface;

/**
 * Los motores que se han quedado sin servicio (créditos agotados, clave rechazada) y cuyos tramos
 * de potencia atiende, mientras tanto, el motor por defecto (22/10/2026, Jorge: «que temporalmente
 * responda el que está activo hasta que se pague»).
 *
 * Lo marcan dos caminos: un turno que choca con un error definitivo
 * (`AiConversationProcessor::conSuplente()`) y la comprobación periódica al dar un motor por
 * caído. Lo DEVUELVE sólo la comprobación periódica, en cuanto el motor vuelve a contestar: es la
 * única que le sigue preguntando al motor de verdad (`SelectorDePotencia::motoresEnUso()` resuelve
 * sin suplencia). Recargar los créditos basta; como mucho, media hora hasta el siguiente sondeo.
 *
 * Vive en la caché de la aplicación: un despliegue la borra, y el primer turno que vuelva a chocar
 * la repone. Caduca a los 7 días por si el sondeo dejara de correr: preferible un fallo que se ve
 * a una suplencia que nadie recuerda haber activado.
 */
final readonly class MotoresFueraDeServicio
{
    private const string CLAVE = 'agente.motor.fuera.';

    private const int VIGENCIA_SEGUNDOS = 7 * 86400;

    public function __construct(private CacheItemPoolInterface $cache) {}

    public function estaFuera(string $motor): bool
    {
        return $this->cache->getItem(self::CLAVE . $motor)->isHit();
    }

    /** @param string $motivo Lo que dijo el proveedor; queda guardado para el log del selector. */
    public function sacar(string $motor, string $motivo): void
    {
        $this->cache->save($this->cache->getItem(self::CLAVE . $motor)->set($motivo)->expiresAfter(self::VIGENCIA_SEGUNDOS));
    }

    /** @return bool Si estaba fuera: quien lo devuelve puede decirlo. */
    public function devolver(string $motor): bool
    {
        $estaba = $this->estaFuera($motor);
        $this->cache->deleteItem(self::CLAVE . $motor);

        return $estaba;
    }
}
