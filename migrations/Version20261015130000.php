<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * El traslado se ofrece con una PREGUNTA, y el catálogo se consulta sólo si dice que sí.
 *
 * `Version20261015120000` le dio al huésped la oferta del traslado tal como la tenía el
 * prospecto: «nuestra agencia (OpenPeru Travel) vende el traslado… búscalo con consultar_tours».
 * Jorge (15/10/2026): «la gente no entenderá que el chat tiene dos personalidades». Quien escribe
 * habla con UN chat; si el alojamiento dice «no tenemos traslado» y en la frase siguiente «nuestra
 * agencia sí», suena a dos casas distintas. Y soltar precio y enlace a quien sólo preguntó cómo
 * llegar es venderle sin que lo pida.
 *
 * Ahora: se contesta cómo llegar y se cierra con la pregunta («¿quieres que te pase la
 * información de un traslado privado desde el aeropuerto?»). Sólo con un sí se llama a
 * `consultar_tours`. La instrucción de que el ALOJAMIENTO no manda a nadie se queda: si el agente
 * deja de saberlo, se lo inventa.
 *
 * Las dos superficies a la vez —guía del huésped y conocimiento del prospecto— porque contarlo
 * distinto según quién pregunte fue justo el fallo que se arregló esta mañana. Por SQL porque las
 * lee sólo el agente; cada `UPDATE` exige el texto de antes, así que una edición a mano posterior
 * se queda como esté.
 */
final class Version20261015130000 extends AbstractMigration
{
    private const string FICHA_GUIA = 'Traslados (general)';
    private const string FICHA_CONOCIMIENTO = 'Cómo llegar desde el aeropuerto (público)';

    private const string GUIA_ANTES = "Si prefiere que le recojan: el ALOJAMIENTO no tiene traslado propio, pero nuestra agencia\n"
        . "(OpenPeru Travel) vende el traslado desde el aeropuerto. Búscalo con consultar_tours y\n"
        . "ofrécelo con su precio. Se reserva como un tour (anotar_pedido_cotizacion): no digas que\n"
        . 'lo manda el alojamiento ni lo des por reservado.';

    private const string GUIA_AHORA = "El ALOJAMIENTO no tiene traslado propio, pero podemos organizarle un traslado privado\n"
        . "desde el aeropuerto. Ofrécelo al final con una pregunta, como un servicio más nuestro:\n"
        . "«¿Quieres que te pase la información de un traslado privado desde el aeropuerto?». Sólo\n"
        . "si dice que sí, búscalo con consultar_tours y dale el precio y el enlace. Si lo quiere,\n"
        . 'anótalo con anotar_pedido_cotizacion y dile que se lo confirmamos; no lo des por reservado.';

    private const string PASO_ANTES = 'Si lo que quiere es que le recojamos nosotros, ofrécele el traslado de la agencia.';

    private const string PASO_AHORA = 'Si lo que quiere es que le recojamos nosotros, pregúntale si quiere la información del traslado privado.';

    private const string CONOCIMIENTO_ANTES = 'Si prefiere que le recojan, nuestra agencia (OpenPeru Travel) vende el traslado desde el aeropuerto: consúltalo con consultar_tours.';

    private const string CONOCIMIENTO_AHORA = 'También podemos organizarle un traslado privado desde el aeropuerto: ofrécelo al final con una pregunta («¿Quieres que te pase la información de un traslado privado desde el aeropuerto?») y, sólo si dice que sí, búscalo con consultar_tours.';

    public function getDescription(): string
    {
        return 'Traslado: el agente lo ofrece con una pregunta y consulta el catálogo sólo con un sí (guía y conocimiento)';
    }

    public function up(Schema $schema): void
    {
        $this->cambiar(self::GUIA_ANTES, self::GUIA_AHORA, self::PASO_ANTES, self::PASO_AHORA, self::CONOCIMIENTO_ANTES, self::CONOCIMIENTO_AHORA);
    }

    public function down(Schema $schema): void
    {
        $this->cambiar(self::GUIA_AHORA, self::GUIA_ANTES, self::PASO_AHORA, self::PASO_ANTES, self::CONOCIMIENTO_AHORA, self::CONOCIMIENTO_ANTES);
    }

    private function cambiar(string $guiaDe, string $guiaA, string $pasoDe, string $pasoA, string $conocimientoDe, string $conocimientoA): void
    {
        // REPLACE sólo cambia algo si la frase está tal cual: repetirlo no hace nada.
        $this->addSql(
            'UPDATE pms_guia_item SET agente_contenido = REPLACE(agente_contenido, ?, ?) WHERE nombre_interno = ?',
            [$guiaDe, $guiaA, self::FICHA_GUIA]
        );
        $this->addSql(
            'UPDATE pms_guia_item SET agente_pasos = JSON_ARRAY(REPLACE(JSON_UNQUOTE(JSON_EXTRACT(agente_pasos, \'$[0]\')), ?, ?)) WHERE nombre_interno = ? AND JSON_LENGTH(agente_pasos) = 1',
            [$pasoDe, $pasoA, self::FICHA_GUIA]
        );
        $this->addSql(
            'UPDATE agent_conocimiento SET contenido = REPLACE(contenido, ?, ?) WHERE nombre_interno = ?',
            [$conocimientoDe, $conocimientoA, self::FICHA_CONOCIMIENTO]
        );
    }
}
