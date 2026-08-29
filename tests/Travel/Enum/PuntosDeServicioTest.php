<?php

declare(strict_types=1);

namespace App\Tests\Travel\Enum;

use App\Travel\Enum\ComponenteTipoEnum as T;
use App\Travel\Enum\PuntosDeServicio as P;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Dónde empieza y dónde termina cada tipo de servicio.
 *
 * De esta clasificación sale lo que se le dice al proveedor en la Orden de Servicio —«dónde
 * recojo, dónde dejo»—, así que un tipo mal puesto no da error: manda una orden con un punto de
 * recojo que nadie va a atender, o deja sin él a quien lo necesita.
 */
final class PuntosDeServicioTest extends TestCase
{
    #[Test]
    public function las_excursiones_LLEVAN_punto_de_recojo(): void
    {
        // ⚠️ Es de lo primero que se coordina con el proveedor: el pool pasa por el hotel. Meter
        // las excursiones en «no aplica» dejaría sin dato justo a las que más lo necesitan.
        foreach ([T::EXCURSION_POOL, T::EXCURSION_PRIVADA] as $tipo) {
            self::assertSame(P::INICIO_Y_FIN, $tipo->puntosDeServicio(), $tipo->value);
            self::assertTrue($tipo->puntosDeServicio()->programaInicio());
            self::assertTrue($tipo->puntosDeServicio()->programaFin());
        }
    }

    #[Test]
    public function lo_que_traslada_tiene_los_dos_puntos(): void
    {
        foreach ([T::TRANSPORTE, T::TREN, T::VUELO] as $tipo) {
            self::assertSame(P::INICIO_Y_FIN, $tipo->puntosDeServicio(), $tipo->value);
        }
    }

    #[Test]
    public function el_guiado_tiene_donde_presentarse_pero_no_destino(): void
    {
        // El guía queda con el pasajero y ahí acaba su parte; devolverlo es del transporte.
        // Ponerle destino sería inventarle una obligación que nadie pactó.
        $puntos = T::GUIADO->puntosDeServicio();

        self::assertSame(P::SOLO_INICIO, $puntos);
        self::assertTrue($puntos->programaInicio());
        self::assertFalse($puntos->programaFin());
    }

    #[Test]
    public function un_ticket_o_una_comida_no_recogen_a_nadie(): void
    {
        // No es «todavía no se sabe»: es que NO APLICA. Dejarlo pendiente invita a rellenarlo.
        foreach ([T::TICKET_HORARIO_FIJO, T::TICKET_HORARIO_VAR,
                  T::ALIMENTACION_HORARIO_FIJO, T::ALIMENTACION_HORARIO_VAR,
                  T::EXTRAS, T::PERSONAL_EXTRA] as $tipo) {
            self::assertSame(P::NINGUNO, $tipo->puntosDeServicio(), $tipo->value);
            self::assertFalse($tipo->puntosDeServicio()->programaInicio(), $tipo->value);
        }
    }

    #[Test]
    public function el_alojamiento_no_traslada_pero_es_el_ANCLA(): void
    {
        // Las dos caras: no recoge a nadie, y a la vez es lo que dice dónde está el pasajero
        // cada noche — de donde sale el punto de recojo de todo lo demás.
        self::assertSame(P::NINGUNO, T::ALOJAMIENTO->puntosDeServicio());
        self::assertTrue(T::ALOJAMIENTO->esAnclaDeUbicacion());
    }

    #[Test]
    public function solo_el_alojamiento_es_ancla(): void
    {
        foreach (T::cases() as $tipo) {
            if ($tipo !== T::ALOJAMIENTO) {
                self::assertFalse($tipo->esAnclaDeUbicacion(), $tipo->value);
            }
        }
    }

    #[Test]
    public function saltan_de_ciudad_el_vuelo_y_el_tren_y_nadie_mas(): void
    {
        foreach (T::cases() as $tipo) {
            self::assertSame(
                $tipo === T::VUELO || $tipo === T::TREN,
                $tipo->esSalto(),
                $tipo->value
            );
        }
    }

    #[Test]
    public function todo_tipo_tiene_respuesta(): void
    {
        // Un `default` en el `match` significa que un caso NUEVO cae en «ninguno» sin avisar.
        // Esto no lo impide —no puede— pero deja constancia de cuántos hay hoy en cada grupo,
        // así que añadir uno y no clasificarlo cambia este número y salta.
        $cuenta = [P::INICIO_Y_FIN->value => 0, P::SOLO_INICIO->value => 0, P::NINGUNO->value => 0];

        foreach (T::cases() as $tipo) {
            $cuenta[$tipo->puntosDeServicio()->value]++;
        }

        self::assertSame(
            // 22/08/2026: SOLO_INICIO pasa de 1 a 2 al entrar CONTACTO.
            // 29/08/2026: INICIO_Y_FIN pasa de 5 a 6 al entrar TRANSPORTE_EXCURSION — recoge en
            // el hotel y devuelve, como cualquier excursión.
            //
            // El número se sube reconociendo el caso, no para que el test calle: si vuelve a
            // saltar es porque hay otro tipo nuevo sin clasificar, y ése es justo el aviso que se
            // quiere. Hoy hizo su trabajo: el tipo nuevo se añadió por otro motivo —quién manda
            // en el display— y este test obligó a decidir también dónde empieza y acaba.
            [P::INICIO_Y_FIN->value => 6, P::SOLO_INICIO->value => 2, P::NINGUNO->value => 7],
            $cuenta
        );
    }

    #[Test]
    public function solo_el_pool_es_un_servicio_compartido(): void
    {
        // Decide dónde se deja al pasajero al terminar: lo privado devuelve al hotel de cada
        // uno, lo compartido deja a todos en el centro — un bus con doce pasajeros de nueve
        // hoteles no puede hacer nueve paradas.
        self::assertTrue(T::EXCURSION_POOL->esCompartido());

        // ⚠️ TRANSPORTE cuenta como privado a propósito: en este catálogo las versiones privadas
        // se montan con un transporte propio («Transporte Vinicunca»), no con EXCURSION_PRIVADA,
        // del que sólo hay dos en todo el maestro. Clasificar por el nombre del caso habría
        // dejado a los privados devolviendo al centro de la ciudad.
        self::assertFalse(T::TRANSPORTE->esCompartido());
        self::assertFalse(T::EXCURSION_PRIVADA->esCompartido());
        self::assertFalse(T::GUIADO->esCompartido());
    }

    #[Test]
    public function el_CONTACTO_tiene_punto_de_encuentro_pero_no_de_entrega(): void
    {
        // ⚠️ No es el guiado. El guiado de Machu Picchu ocurre ARRIBA; el contacto, en la estación
        // o en el hotel, horas antes y a cuatro horas de distancia. Si el contacto no tuviera
        // punto propio, el «dónde recojo» de la orden diría dónde se guía — y el proveedor iría
        // al sitio equivocado con una orden que se lee perfectamente bien.
        self::assertSame(P::SOLO_INICIO, T::CONTACTO->puntosDeServicio());
        self::assertTrue(T::CONTACTO->puntosDeServicio()->programaInicio());
        self::assertFalse(T::CONTACTO->puntosDeServicio()->programaFin());
    }

    #[Test]
    public function el_contacto_encabeza_el_manifiesto(): void
    {
        // Es lo que ocurre antes que nada ese día: tiene que leerse antes que el servicio al que
        // da paso, o el proveedor lo encuentra debajo del guiado que viene después.
        self::assertLessThan(T::GUIADO->prioridad(), T::CONTACTO->prioridad());
    }
}
