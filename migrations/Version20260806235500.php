<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Siembra `agente_terminos` en los ítems de la guía: con qué palabras pregunta la gente por cada
 * tema, para que el asistente los encuentre al responder por chat.
 *
 * El título está escrito para leerse, no para buscarse: el ítem se llama «Uso de la ducha» y el
 * huésped escribe «no sale agua caliente», «el calentador no enciende» o «hot water». Sin estos
 * términos el agente contestaba que la guía no dice nada del tema, teniéndolo escrito.
 *
 * ### Por qué por `nombre_interno` y no por ID
 *
 * Los UUID de los ítems no tienen por qué coincidir entre entornos, y esta migración se ejecuta
 * en los dos. `nombre_interno` es el identificador que el operador ya usa en el panel y es
 * estable. Se agrupa con `LIKE 'Ducha (casa%'` porque el mismo tema está repetido una vez por
 * casita (7 duchas, 7 puertas, 7 descripciones): son 40 ítems y 16 conceptos.
 *
 * ### Idempotente y no destructiva
 *
 * Todos los UPDATE llevan `agente_terminos IS NULL`. Si alguien ya escribió los suyos a mano en
 * producción, esta migración no se los pisa — y volver a ejecutarla no deshace ediciones
 * posteriores. Es siembra inicial, no una fuente de verdad: a partir de aquí se editan en el
 * panel («🔎 Cómo lo preguntan»), sin tocar código.
 *
 * Los términos van en español e inglés porque se pregunta en los dos, y sin acentos además de
 * con ellos: la búsqueda normaliza, pero un término mal escrito aquí no cuesta nada y cubre al
 * huésped que teclea deprisa.
 *
 * Ver docs/PmsGuiaHuesped.md §6.2.
 */
final class Version20260806235500 extends AbstractMigration
{
    /**
     * patrón de `nombre_interno` => términos.
     *
     * Salen de leer el contenido real de cada ítem, no del título: «Limpieza» habla de que va
     * incluida una vez por estadía (de ahí «toallas», «sábanas», «cambio»), y «Pago» del
     * depósito de garantía.
     */
    private const array TERMINOS = [
        'Ducha (casa%' => 'ducha, agua caliente, calentador, calefon, terma, gas, temperatura, '
            . 'no sale agua caliente, agua fria, banarse, shower, hot water, water heater, no hot water',

        'Puerta (casa%' => 'puerta, entrada, como entro, como se abre, cerradura, chapa, llave, '
            . 'ingresar, acceso, door, entrance, how to get in, lock',

        'Descripción (casa%' => 'departamento, casa, apartamento, habitaciones, cuartos, camas, '
            . 'cocina, bano, capacidad, cuantas personas, que tiene, equipamiento, tv, netflix, '
            . 'apartment, rooms, beds, kitchen, amenities',

        'Album (casa%' => 'fotos, imagenes, album, como es, ver la casa, photos, pictures, gallery',

        'Wifi (general)' => 'wifi, internet, red, clave, contrasena, password, señal, conectar, '
            . 'no hay internet, wi-fi, network, no internet',

        'Llaves (general)' => 'llaves, caja fuerte, caja de seguridad, codigo, clave de la caja, '
            . 'donde estan las llaves, recoger llaves, keys, safe, lockbox, key box',

        'Perdida llaves (general)' => 'perdi las llaves, llave perdida, se me perdieron las llaves, '
            . 'duplicado, copia de llave, multa, lost keys, lost my key',

        'Ubicación (general)' => 'direccion, ubicacion, donde queda, como llegar, mapa, saphi, '
            . 'taxi, address, location, how to get there, map',

        'Despues de ingresar (general)' => 'ya llegue, ya entre, que hago ahora, confirmar llegada, '
            . 'revisar, danos, algo roto, mancha, reportar, checked in, i arrived, what now, damage',

        'Limpieza (general)' => 'limpieza, limpian, aseo, toallas, sabanas, cambio de toallas, '
            . 'mucama, basura, cada cuanto limpian, cleaning, towels, sheets, housekeeping, trash',

        'Calefactor (general)' => 'calefactor, calefaccion, estufa, frio, hace frio, calor, '
            . 'alquilar calefactor, cuanto cuesta el calefactor, heater, heating, cold, stove',

        'Reglas (general)' => 'reglas, normas, se puede, esta permitido, fiesta, ruido, fumar, '
            . 'mascotas, visitas, horario de silencio, lavar ropa, rules, house rules, allowed, '
            . 'party, smoking, pets, noise',

        'Pago (general)' => 'pago, pagar, deposito, garantia, cuanto debo, saldo, cobro, tarjeta, '
            . 'efectivo, payment, deposit, how much do i owe, balance',

        'Registro de huéspedes (general)' => 'registro, registrar, documento, dni, pasaporte, '
            . 'datos de los huespedes, lista de personas, migraciones, registration, passport, id, '
            . 'guest list',

        'Horario solicitudes (general)' => 'horario de atencion, a que hora atienden, hasta que '
            . 'hora, contacto, pedir algo, solicitud, support hours, opening hours, contact',

        'Early check in Late Check Out' => 'horario, check in, check out, a que hora entro, '
            . 'a que hora salgo, entrada, salida, llegar antes, salir tarde, quedarme mas, '
            . 'early check in, late check out, what time, arrival time, departure time',
    ];

    public function getDescription(): string
    {
        return 'Siembra los términos de búsqueda del asistente en los ítems de la guía (no pisa los ya escritos).';
    }

    public function up(Schema $schema): void
    {
        foreach (self::TERMINOS as $patron => $terminos) {
            $this->addSql(
                'UPDATE pms_guia_item SET agente_terminos = :terminos '
                . 'WHERE nombre_interno LIKE :patron AND agente_terminos IS NULL',
                ['terminos' => $terminos, 'patron' => $patron]
            );
        }
    }

    /**
     * Deja en NULL SÓLO lo que esta migración escribió, comparando el valor exacto.
     *
     * Un `SET agente_terminos = NULL` a secas borraría también lo que un operador hubiera
     * escrito o corregido después, que es justo el trabajo que no se puede rehacer solo.
     */
    public function down(Schema $schema): void
    {
        foreach (self::TERMINOS as $patron => $terminos) {
            $this->addSql(
                'UPDATE pms_guia_item SET agente_terminos = NULL '
                . 'WHERE nombre_interno LIKE :patron AND agente_terminos = :terminos',
                ['terminos' => $terminos, 'patron' => $patron]
            );
        }
    }
}
