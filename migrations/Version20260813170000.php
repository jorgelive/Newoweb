<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Escalera para cinco ítems más: lo que hoy sale a la primera y no debería.
 *
 * ### El criterio, en dos ópticas
 *
 * **La del cliente**: pregunta una cosa y recibe advertencias de otra. Quien pregunta cómo llegar
 * desde el aeropuerto lee «NO tenemos traslado propio» antes que la respuesta útil; quien
 * pregunta por un calefactor recibe una justificación del precio que nadie pidió —y que le
 * planta la idea de que es caro—.
 *
 * **La del dueño**: eso espanta y encima genera trabajo. La información no sobra; sobra **en el
 * primer mensaje**. Cuando de verdad haga falta —porque objete, insista o pregunte por ello—
 * sale entera.
 *
 * ### ⚠️ La distinción que decide qué se mueve
 *
 * Lo que es **instrucción al agente** se queda en el peldaño 0 aunque suene negativo; lo que es
 * **una negativa dicha al cliente** es lo que se mueve.
 *
 * «NO tenemos traslado propio: no lo prometas nunca» tiene que seguir en el peldaño 0: si el
 * agente deja de saberlo, se lo inventa —y prometer un traslado que no existe es peor que
 * cualquier frase seca—. Lo que se mueve es *decírselo de entrada*, no el dato.
 *
 * ### Los cinco
 *
 * ```
 *   Calefactor       paso 1  por qué cuesta lo que cuesta (sólo si le parece caro)
 *   Estacionamiento  paso 1  no sabemos el precio de la cochera ni reservamos sitio
 *   Limpieza         paso 1  la limpieza extra tiene coste
 *   Traslados        paso 1  no es nuestro, y lo del recojo de Booking
 *   Early check-in   pasos 1 y 2  el margen real, y luego que tiene coste
 * ```
 *
 * `Cancelación (general)` se revisó y **no lleva escalera**: «después se cobra la primera noche»
 * es la respuesta a la pregunta, no una advertencia de más. No todos los temas la quieren.
 */
final class Version20260813170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Escalera para calefactor, estacionamiento, limpieza, traslados y early check-in: '
            . 'lo que hoy se dice a la primera pasa a decirse cuando hace falta.';
    }

    /**
     * @return array<string, array{0: string, 1: list<string>}> nombre => [peldaño 0, pasos]
     */
    private function contenidos(): array
    {
        return [
            // El argumento del kWh ya venía con su propia instrucción en prosa —«no saques este
            // argumento tú; solo si el lo menciona»—, que es esta escalera escrita a mano.
            'Calefactor (general)' => [
                <<<'TXT'
                Alquilamos calefactores: 20 soles por turno de 12 horas. Los turnos son noche (7 pm a 7
                am) y dia (7 am a 7 pm). No se alquilan por horas sueltas, se cobra el turno completo.

                Se encienden en remoto desde nuestro sistema, asi que basta con avisarnos por el chat.
                Tienen que quedarse enchufados, y llevan termostato que regula la temperatura solo.
                TXT,
                [
                    <<<'TXT'
                    Solo si le parece caro o pregunta por que se cobra aparte: en Cusco la electricidad
                    esta a S/ 0.93 el kWh y un calefactor consume unos 24 kWh en un turno completo, casi
                    lo mismo que cuesta el alquiler. Diselo sin ponerte a la defensiva: es un dato, no
                    una discusion.
                    TXT,
                ],
            ],

            // Lo de «no es area vigilada» SE QUEDA en el peldaño 0: el propio texto dice que se
            // dice siempre porque es honesto y evita el reclamo. Es una decision del dueño, no
            // una advertencia de mas.
            'Estacionamiento (general)' => [
                <<<'TXT'
                Dos opciones, y ninguna de las dos es nuestra: justo al frente hay una playa de
                estacionamiento publica y GRATUITA, y a una cuadra una cochera privada DE PAGO.

                Ofrece las dos con confianza, es de lo que mas preguntan los que llegan en coche.

                Di siempre que la gratuita no es un area vigilada. Es honesto, y nos evita el reclamo si
                le pasa algo al vehiculo.
                TXT,
                [
                    <<<'TXT'
                    Si pide el precio de la cochera o quiere que le reservemos sitio: no sabemos lo que
                    cobran y no podemos apartar plaza en ninguna de las dos. Dilo tal cual, sin estimar
                    un precio ni prometer que lo consultas, y avisa al equipo.
                    TXT,
                ],
            ],

            // «Una vez por estadia, no diaria» es la respuesta y se queda. Lo que se mueve es el
            // coste: quien pregunta cuando limpian no esta pidiendo un presupuesto.
            'Limpieza (general)' => [
                <<<'TXT'
                La limpieza esta incluida una vez por estadia, no de forma diaria.

                Basura: el carro recolector pasa todas las madrugadas, asi que conviene sacarla a partir
                de las 9 de la noche. La plataforma para dejarla esta justo enfrente, cruzando la calle.
                TXT,
                [
                    <<<'TXT'
                    Si pide limpieza adicional durante su estancia, o cambio de toallas y sabanas: se
                    puede coordinar, y tiene un coste adicional. Ofrecelo con naturalidad —es un servicio,
                    no un problema— y avisa al equipo para que le digan el precio y cuadren el dia.
                    TXT,
                ],
            ],

            // ⚠️ La primera linea NO es contenido para el huesped: es la barrera que impide que el
            // agente prometa un traslado que no existe. Se queda, con el matiz de que no hace
            // falta soltarla de entrada.
            'Traslados (general)' => [
                <<<'TXT'
                NO tenemos traslado propio: no lo prometas nunca ni digas que mandamos a alguien. Pero
                tampoco hace falta que empieces por ahi: contesta lo util.

                Uber funciona en Cusco y hay taxis dentro del aeropuerto; basta con que den la direccion.
                El coche llega hasta la puerta: la calle es plana y ancha, sin escaleras ni ultimo tramo
                a pie. Eso tranquiliza a quien viene con equipaje pesado o con personas mayores, y
                conviene decirlo aunque no lo pregunte.
                TXT,
                [
                    <<<'TXT'
                    Si insiste en que le recojamos o pregunta directamente por el traslado del
                    alojamiento: no lo hacemos, dilo claro y sin rodeos.

                    Y si su reserva es de Booking y pregunta por el recojo en aeropuerto: eso lo ofrece
                    Booking a sus clientes preferentes, no nosotros. Que les pase a ELLOS sus datos de
                    vuelo. Nosotros no podemos gestionarlo ni confirmarlo, asi que no digas que lo
                    revisas.
                    TXT,
                ],
            ],

            // Tres peldaños: la hora y el almacen (bueno), el margen real (condicionado), y el
            // coste (lo que espanta si sale antes de tiempo).
            'Early check in Late Check Out' => [
                <<<'TXT'
                Horarios: entrada desde "hora_check_in", salida hasta "hora_check_out", que vienen en
                esta misma respuesta. Usa esas, no des una hora estandar.

                SU GUIA. Cuando hable de su llegada, recuerdale que las instrucciones de ingreso estan en
                su guia y pasale el enlace "guide_url" de consultar_mi_reserva.

                EQUIPAJE. Tenemos almacen: puede dejar las maletas si llega antes de la entrada o si ya
                hizo el check-out y sigue en la ciudad. Ofrecelo con confianza. Si pregunta el costo,
                avisa al equipo.

                Tambien se puede guardar varios dias, por ejemplo mientras hace un trek: que avise con un
                dia de antelacion para coordinarlo, y que diga cuantos bultos son.
                TXT,
                [
                    <<<'TXT'
                    Si pide entrar antes o salir despues, mira si la casita esta libre alrededor de su
                    estancia: con el huesped viene en el contexto; desde el panel, en "casita_ese_dia" y
                    "casita_tras_su_salida" de consultar_mi_reserva.

                    - Sale otro huesped ese dia: no hay margen. Ofrece almacen y avisa al equipo.
                    - La casita esta libre: cabe pedir hasta unas 3 horas antes de la entrada. Dile que lo
                      ves posible y que lo confirma el equipo, y avisale de que la limpieza podria estar
                      aun trabajando y de que la llave quiza no este todavia en la caja.
                    - Entra otro huesped el dia que se va: la salida es a su hora. Almacen y avisar.
                    - Sin nadie entrando: cabe pedir hasta 1 hora extra a la salida, misma formula.

                    Eso es interno: da la conclusion, nunca digas que hay otro huesped ni sus horas.

                    NUNCA lo concedas tu, ni con la casita libre: dices que parece posible y escalas.
                    TXT,
                    <<<'TXT'
                    Si lo que pide es mas que eso —entrar a media manana, quedarse hasta las 3— ya es
                    entrada temprana o salida tarde de verdad: tiene costo, se coordina el dia anterior
                    segun disponibilidad y lo confirma el equipo.

                    Si quiere asegurarlo pase lo que pase, la unica forma es reservar una noche
                    adicional. Ofrecelo solo si insiste en tener certeza, no antes.
                    TXT,
                ],
            ],
        ];
    }

    public function up(Schema $schema): void
    {
        foreach ($this->contenidos() as $nombre => [$peldano0, $pasos]) {
            $this->addSql(
                'UPDATE pms_guia_item SET agente_contenido = ?, agente_pasos = ? WHERE nombre_interno = ?',
                [
                    $this->limpiar($peldano0),
                    json_encode(
                        array_map($this->limpiar(...), $pasos),
                        JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
                    ),
                    $nombre,
                ]
            );
        }
    }

    /**
     * Devuelve cada ítem a un solo texto: el peldaño 0 con sus pasos pegados detrás.
     *
     * No reconstruye el original palabra por palabra —el orden de algunos bloques cambió al
     * repartirlos—, pero deja al agente sabiendo exactamente lo mismo que antes, que es lo que
     * importa si hay que volver atrás con prisa.
     */
    public function down(Schema $schema): void
    {
        foreach ($this->contenidos() as $nombre => [$peldano0, $pasos]) {
            $entero = $this->limpiar($peldano0);

            foreach ($pasos as $paso) {
                $entero .= "\n\n" . $this->limpiar($paso);
            }

            $this->addSql(
                "UPDATE pms_guia_item SET agente_contenido = ?, agente_pasos = '[]' WHERE nombre_interno = ?",
                [$entero, $nombre]
            );
        }
    }

    /** Quita la sangría del heredoc: no debe viajar al prompt. */
    private function limpiar(string $texto): string
    {
        return trim(preg_replace('/^[ \t]+/m', '', $texto) ?? $texto);
    }
}
