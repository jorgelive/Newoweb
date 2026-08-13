<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Carga la escalera de las siete duchas.
 *
 * Es migración de DATOS, no de esquema: el campo `agente_pasos` ya existe
 * (Version20260813124940) y aquí se llena. Va en una migración y no a mano en el panel porque
 * son siete fichas con tres variantes de texto, y el criterio de qué frase va en qué peldaño
 * merece quedar escrito y ser repetible.
 *
 * ### El criterio
 *
 * Al que sólo pregunta cómo funciona la ducha NO se le habla de averías: eso ya está en el
 * peldaño 0 (`agente_contenido`) y no se toca. Aquí van los peldaños que salen **sólo si vuelve
 * diciendo que no le funciona**.
 *
 * ```
 *   paso 1   todas       abrir SOLO la caliente, del todo, y esperar un minuto
 *   paso 2   2, 3, 4, 7  encender una hornilla: comparten el balón con la cocina
 * ```
 *
 * ### Las tres variantes, y por qué
 *
 * - **Casas 1–5**: un baño, grifo de dos manijas. La caliente es la izquierda.
 * - **Casas 6 y 7**: dos baños que no funcionan igual —uno de una sola palanca, otro de dos
 *   manijas—, así que la instrucción tiene que cubrir los dos o el huésped no sabrá cuál es la
 *   suya.
 * - **Casas 2, 3, 4 y 7**: comparten el balón de gas entre ducha y cocina. Eso convierte la
 *   hornilla en un diagnóstico: dice si falta gas o si el que falla es el calentador, que es la
 *   diferencia entre mandar un balón y mandar un técnico.
 *
 * ⚠️ Casa 6 tiene dos baños pero NO comparte gas con la cocina: se queda en un solo paso.
 */
final class Version20260813150000 extends AbstractMigration
{
    /** Paso 1, casas de un baño con grifo de dos manijas. */
    private const string PASO1_DOS_MANIJAS = <<<'TXT'
        Que abra SOLO la manija izquierda, la del agua caliente, del todo, y espere un minuto sin
        tocar la fría.

        Estos calentadores necesitan un chorro fuerte para encenderse: con la fría abierta o el
        agua a medio abrir, muchas veces no llegan a arrancar. Es la causa más común y se
        resuelve así.
        TXT;

    /** Paso 1, casas con dos baños distintos. */
    private const string PASO1_DOS_BANOS = <<<'TXT'
        Que lo pruebe según el baño en el que esté:

        - Baño de una sola palanca: llevarla del todo hacia el lado caliente, la izquierda.
        - Baño de dos manijas: abrir sólo la izquierda, la caliente.

        En los dos casos, abierto del todo y esperando un minuto sin tocar la fría. Estos
        calentadores necesitan un chorro fuerte para encenderse: con la fría abierta o el agua a
        medio abrir, muchas veces no llegan a arrancar.
        TXT;

    /** Paso 2, sólo donde la ducha y la cocina comparten balón. */
    private const string PASO2_GAS_COMPARTIDO = <<<'TXT'
        Pídele que vaya a la cocina y encienda una hornilla: en esta casita la ducha y la cocina
        comparten el mismo balón de gas, así que la hornilla dice si hay gas o no.

        - Si la hornilla NO prende, el balón está vacío. No es una avería: se le cambia. Díselo
          así y avisa al equipo indicando que es cambio de balón.
        - Si la hornilla SÍ prende, hay gas y entonces el problema es del calentador. Avisa al
          equipo diciendo que la hornilla enciende: hace falta un técnico, no un balón.

        Si todavía no lo ha comprobado, pídeselo y espera su respuesta antes de avisar a nadie.
        TXT;

    public function getDescription(): string
    {
        return 'Pasos del asistente para las siete duchas: reintento con caudal alto y, donde el '
            . 'gas es compartido, la comprobación de la hornilla.';
    }

    public function up(Schema $schema): void
    {
        $unBano = $this->limpiar(self::PASO1_DOS_MANIJAS);
        $dosBanos = $this->limpiar(self::PASO1_DOS_BANOS);
        $gas = $this->limpiar(self::PASO2_GAS_COMPARTIDO);

        $pasosPorCasa = [
            'Ducha (casa 1)' => [$unBano],
            'Ducha (casa 2)' => [$unBano, $gas],
            'Ducha (casa 3)' => [$unBano, $gas],
            'Ducha (casa 4)' => [$unBano, $gas],
            'Ducha (casa 5)' => [$unBano],
            'Ducha (casa 6)' => [$dosBanos],
            'Ducha (casa 7)' => [$dosBanos, $gas],
        ];

        foreach ($pasosPorCasa as $nombre => $pasos) {
            $this->addSql(
                'UPDATE pms_guia_item SET agente_pasos = ? WHERE nombre_interno = ?',
                [json_encode($pasos, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), $nombre]
            );
        }
    }

    public function down(Schema $schema): void
    {
        // Vaciar, no borrar la columna: se vuelve al comportamiento de antes —una sola respuesta
        // por tema— sin tocar el `agente_contenido`, que esta migración nunca modificó.
        $this->addSql("UPDATE pms_guia_item SET agente_pasos = '[]' WHERE nombre_interno LIKE 'Ducha (casa %)'");
    }

    /**
     * Quita la sangría del heredoc y deja el texto como se le va a enseñar al modelo.
     *
     * El heredoc se sangra para que el código se lea; esa sangría no debe viajar al prompt.
     */
    private function limpiar(string $texto): string
    {
        return trim(preg_replace('/^[ \t]+/m', '', $texto) ?? $texto);
    }
}
