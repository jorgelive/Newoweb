<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * La ficha «Traslados (general)» deja de negarle al huésped el traslado que vende la agencia.
 *
 * El 11/10/2026 se cambió la ficha de CONOCIMIENTO del aeropuerto para ofrecer el traslado de
 * OpenPeru Travel (`Version20261011120000`), pero esa ficha sólo la ven el prospecto, el
 * interesado y el equipo. Al HUÉSPED le contesta la guía, `consultar_guia` → «Traslados», que
 * seguía diciendo «NO tenemos traslado propio» y, si insistía, «no lo hacemos, dilo claro y sin
 * rodeos». Y el huésped ve también `consultar_tours`, con el catálogo «Traslados» marcado «lo
 * ofrece el agente» desde el 13/10: un «no» en la guía y un «sí» en el catálogo, y el modelo
 * eligiendo. Ya pasó el 07/10: «¿Disponen de servicio de traslado al aeropuerto?» → «No contamos
 * con servicio de traslado propio».
 *
 * La instrucción de que el ALOJAMIENTO no manda a nadie se queda —si el agente deja de saberlo,
 * se lo inventa—; lo que cambia es que ahora va acompañada de la oferta de la agencia.
 *
 * Por SQL porque `agente_contenido` y `agente_pasos` los lee sólo el agente: no se traducen
 * (CLAUDE.md, «Qué entra por migración»). Sólo se reescribe si la ficha sigue diciendo lo de
 * antes: una corrección a mano posterior se queda como esté.
 */
final class Version20261015120000 extends AbstractMigration
{
    private const string FICHA = 'Traslados (general)';

    private const string CONTENIDO_ANTES = "NO tenemos traslado propio: no lo prometas nunca ni digas que mandamos a alguien. Pero\n"
        . "tampoco hace falta que empieces por ahí: contesta lo útil.\n\n"
        . "Uber funciona en Cusco y hay taxis dentro del aeropuerto; basta con que den la dirección.\n"
        . "El coche llega hasta la puerta: la calle es plana y ancha, sin escaleras ni último tramo\n"
        . "a pie. Eso tranquiliza a quien viene con equipaje pesado o con personas mayores, y\n"
        . 'conviene decirlo aunque no lo pregunte.';

    private const string CONTENIDO_AHORA = "Uber funciona en Cusco y hay taxis dentro del aeropuerto; basta con que den la dirección.\n"
        . "El coche llega hasta la puerta: la calle es plana y ancha, sin escaleras ni último tramo\n"
        . "a pie. Eso tranquiliza a quien viene con equipaje pesado o con personas mayores, y\n"
        . "conviene decirlo aunque no lo pregunte.\n\n"
        . "Si prefiere que le recojan: el ALOJAMIENTO no tiene traslado propio, pero nuestra agencia\n"
        . "(OpenPeru Travel) vende el traslado desde el aeropuerto. Búscalo con consultar_tours y\n"
        . "ofrécelo con su precio. Se reserva como un tour (anotar_pedido_cotizacion): no digas que\n"
        . 'lo manda el alojamiento ni lo des por reservado.';

    private const string PASO_ANTES = "Si insiste en que le recojamos o pregunta directamente por el traslado del\n"
        . "alojamiento: no lo hacemos, dilo claro y sin rodeos.\n\n"
        . "Y si su reserva es de Booking y pregunta por el recojo en aeropuerto: eso lo ofrece\n"
        . "Booking a sus clientes preferentes, no nosotros. Que les pase a ELLOS sus datos de\n"
        . "vuelo. Nosotros no podemos gestionarlo ni confirmarlo, así que no digas que lo\n"
        . 'revisas.';

    private const string PASO_AHORA = "Si su reserva es de Booking y pregunta por el recojo que ofrece Booking a sus\n"
        . "clientes preferentes: eso lo gestiona Booking, no nosotros. Que les pase a ELLOS sus datos\n"
        . "de vuelo. Nosotros no podemos gestionarlo ni confirmarlo, así que no digas que lo revisas.\n"
        . 'Si lo que quiere es que le recojamos nosotros, ofrécele el traslado de la agencia.';

    public function getDescription(): string
    {
        return 'pms_guia_item «Traslados (general)»: el agente ofrece al huésped el traslado de la agencia';
    }

    public function up(Schema $schema): void
    {
        $this->cambiar(self::CONTENIDO_ANTES, self::CONTENIDO_AHORA, self::PASO_ANTES, self::PASO_AHORA);
    }

    public function down(Schema $schema): void
    {
        $this->cambiar(self::CONTENIDO_AHORA, self::CONTENIDO_ANTES, self::PASO_AHORA, self::PASO_ANTES);
    }

    private function cambiar(string $contenidoDe, string $contenidoA, string $pasoDe, string $pasoA): void
    {
        $this->addSql(
            'UPDATE pms_guia_item SET agente_contenido = ? WHERE nombre_interno = ? AND agente_contenido = ?',
            [$contenidoA, self::FICHA, $contenidoDe]
        );
        $this->addSql(
            'UPDATE pms_guia_item SET agente_pasos = JSON_ARRAY(?) WHERE nombre_interno = ? AND JSON_UNQUOTE(JSON_EXTRACT(agente_pasos, \'$[0]\')) = ? AND JSON_LENGTH(agente_pasos) = 1',
            [$pasoA, self::FICHA, $pasoDe]
        );
    }
}
