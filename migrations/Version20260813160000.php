<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * La escalera de «Pago (general)»: la objeción del importe deja de salir a la primera.
 *
 * ### Qué se mueve y por qué
 *
 * El ítem tenía 1756 caracteres con cinco temas pegados, y quien preguntaba «¿el pago no es por
 * Booking?» recibía de golpe —además de la respuesta— que el canal puede mostrar otra cifra, por
 * qué se cobran el suplemento de limpieza y el cargo por servicio, y que si los números no
 * cuadran avisaremos al equipo. Es responderle a un problema que no tiene.
 *
 * Ese bloque pasa al paso 1: sale **sólo si dice que ve otro importe**, ya sea porque insiste o
 * porque `ConsultarCuentaSkill` le mandó aquí con `ya_lo_intento` (ver `si_discute_el_importe`).
 *
 * ⚠️ **Esto es lo que hace verdadera esa promesa.** Sin este paso cargado, el puntero de la skill
 * de cuenta apunta a un tema sin escalera y el huésped que discute la cifra recibe otra vez
 * «quién cobra», que es justo lo que ya le habíamos dicho. Por eso va en el mismo despliegue.
 *
 * ### Lo que NO se toca todavía
 *
 * El depósito de garantía y los comprobantes siguen dentro del peldaño 0 con su instrucción de
 * «sólo si pregunta». No son peldaños —no dependen de que insista, sino de que pregunte por
 * ellos— y sacarlos exige crear ítems nuevos, que se publican en la app del huésped en siete
 * idiomas. Eso es una decisión de contenido, no de código.
 */
final class Version20260813160000 extends AbstractMigration
{
    /**
     * Peldaño 0: lo que sirve a quien pregunta cómo pagar.
     *
     * Conserva el depósito con su instrucción original: quitarlo sin darle otro sitio haría que
     * el asistente lo NEGARA —probado en esta misma guía, respondía «no es necesario dejar
     * ningún depósito»—.
     */
    private const string PELDANO_0 = <<<'TXT'
        Quien cobra depende del canal. Compruebalo con consultar_mi_reserva, campo channel_name.

        - Airbnb: la plataforma ya cobro todo. No paga nada al llegar y NO se le pide deposito.
        - Booking.com y reservas directas: el pago no lo cobra la plataforma, se abona directamente a
          nosotros. Se hace al llegar, o se puede adelantar antes.

        POR DONDE se paga NO lo digas de aqui: pidelo con consultar_medios_pago. Esa skill tiene el
        catalogo real y ya filtra por si el huesped paga desde Peru o desde fuera. Si los nombraramos
        aqui, el dia que se desactive uno seguiriamos ofreciendolo.

        La cifra buena es siempre la de consultar_cuenta.

        Deposito de garantia: SOLO si pregunta por el. No lo saques tu.
        Aplica a Booking.com y reservas directas; a los de Airbnb no. Si pregunta: son S/ 300 que se
        dejan al hacer el check-in y se devuelven integros al salir, cuando se comprueba que estan las
        llaves y no hay danos. No es un cobro, es un deposito en garantia.

        Comprobantes: por el chat de Booking NO se pueden adjuntar imagenes. Si te dice que ya pago y
        quiere mandar la captura, no le pidas que la adjunte aqui: dile que nos la mande por WhatsApp
        o que nos avise y el equipo se la pide.
        TXT;

    /** Paso 1: la objeción del importe. Sale sólo cuando dice que ve otra cifra. */
    private const string PASO_1 = <<<'TXT'
        El canal muestra su tarifa, que puede no incluir el suplemento de limpieza ni el cargo por
        servicio, y nunca refleja los pagos que ya nos hizo directamente. Por eso ve un numero
        distinto: no es un error, son dos cosas que miden cosas distintas. La cifra buena es la de
        consultar_cuenta.

        El suplemento de limpieza y el cargo por servicio son cargos fijos de la reserva, no extras
        opcionales, y estaban en el total desde que reservo. NO te inventes una justificacion de en
        que se gasta cada uno.

        Si aun asi no le cuadra, no lo discutas: avisa al equipo con las dos cifras, la nuestra y la
        que dice ver el.
        TXT;

    /** El texto que se retira del peldaño 0, para poder devolverlo en `down()`. */
    private const string BLOQUE_RETIRADO = <<<'TXT'
        Si dice que en la app del canal ve OTRO importe: es normal y no es un error. El canal muestra
        su tarifa, que puede no incluir el suplemento de limpieza ni el cargo por servicio, y nunca
        refleja los pagos que ya nos hizo directamente. La cifra buena es la de consultar_cuenta.
        Explicaselo asi y, si insiste o los numeros no cuadran, avisa al equipo.

        Si pregunta por que se cobra el suplemento de limpieza o el cargo por servicio: son cargos
        fijos de la reserva, no extras opcionales, y ya estaban en el total desde que reservo. NO te
        inventes una justificacion de en que se gasta cada uno; si quiere el detalle, avisa al equipo.
        TXT;

    public function getDescription(): string
    {
        return 'Pago (general): la objeción del importe del canal pasa a ser el paso 1 en vez de '
            . 'salir en la primera respuesta.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'UPDATE pms_guia_item SET agente_contenido = ?, agente_pasos = ? WHERE nombre_interno = ?',
            [
                $this->limpiar(self::PELDANO_0),
                json_encode([$this->limpiar(self::PASO_1)], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'Pago (general)',
            ]
        );
    }

    public function down(Schema $schema): void
    {
        // Se devuelve el texto entero tal y como estaba: el bloque retirado vuelve al final del
        // peldaño 0, que es donde vivía.
        $this->addSql(
            'UPDATE pms_guia_item SET agente_contenido = ?, agente_pasos = ? WHERE nombre_interno = ?',
            [
                $this->limpiar(self::PELDANO_0) . "\n\n" . $this->limpiar(self::BLOQUE_RETIRADO),
                '[]',
                'Pago (general)',
            ]
        );
    }

    /** Quita la sangría del heredoc: no debe viajar al prompt. */
    private function limpiar(string $texto): string
    {
        return trim(preg_replace('/^[ \t]+/m', '', $texto) ?? $texto);
    }
}
