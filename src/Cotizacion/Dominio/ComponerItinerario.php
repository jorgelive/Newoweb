<?php

declare(strict_types=1);

namespace App\Cotizacion\Dominio;

use App\Dominio\Contrato\OperacionDominioInterface;

/**
 * Componer el itinerario de una cotización: días, bloques, estadías y su orden.
 *
 * Es la primera operación del dominio compartido, y sirve de plantilla: **una clase, sin registro
 * que tocar**. La regla no está aquí —vive en `dominio/cotizacion/itinerarioVista.ts`, que también
 * ejecuta el navegador—; esta clase sólo dice cuál es su puerta y qué contrato habla.
 *
 * ⚠️ **La entrada es la serialización PÚBLICA de la cotización** (`pax_cotizacion:read`), no la
 * del operador. Es deliberado: el módulo declara los doce campos que lee y la pública los tiene
 * todos, así que mandar la del operador sería darle al cálculo campos que no necesita —entre
 * ellos los que la API decide no enseñar al cliente—.
 */
final class ComponerItinerario implements OperacionDominioInterface
{
    public function puntoDeEntrada(): string
    {
        return 'cotizacion/itinerario.cli.ts';
    }

    public function contrato(): string
    {
        return 'itinerario@1';
    }
}
