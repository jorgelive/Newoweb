<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Estacionamiento: el teléfono de la cochera, y dejar de decir «no se puede reservar».
 *
 * ### Qué estaba confuso
 *
 * El texto decía «no podemos reservar sitio en ninguna de las dos», y mete en la misma frase dos
 * cosas que no se parecen:
 *
 * - La playa pública **no admite reserva de nadie**: es por orden de llegada. Decir «no podemos
 *   reservarla» suena a que nos falta un permiso, cuando lo que pasa es que no existe la reserva.
 * - En la cochera privada **sí se puede preguntar** — sólo que hay que hacerlo con ellos, no con
 *   nosotros.
 *
 * ### Lo que cambia de verdad: hay un teléfono
 *
 * La cochera de Sapi atiende en el **+51 984 631 997**. Con ese dato, lo que antes era un
 * escalado —«pide el precio, avisa al equipo»— pasa a ser una respuesta que resuelve: el huésped
 * pregunta directamente y nadie del equipo se entera.
 *
 * Se corrige en los dos sitios a la vez, guía y conocimiento, para que no se separen — es la
 * misma lección que las siete duchas.
 *
 * Texto plano del agente, sin listeners de por medio: por migración (CLAUDE.md §Qué entra por
 * migración y qué tiene que entrar por comando).
 */
final class Version20260813200000 extends AbstractMigration
{
    private const string TELEFONO = '+51 984 631 997';

    public function getDescription(): string
    {
        return 'Estacionamiento: se añade el teléfono de la cochera de Sapi y se aclara por qué '
            . 'no se aparta sitio en la playa pública.';
    }

    public function up(Schema $schema): void
    {
        // ── Guía: el peldaño 0 explica las dos opciones sin la frase confusa ──
        $this->addSql(
            'UPDATE pms_guia_item SET agente_contenido = ?, agente_pasos = ? WHERE nombre_interno = ?',
            [
                $this->guiaPeldano0(),
                json_encode([$this->guiaPaso1()], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'Estacionamiento (general)',
            ]
        );

        // ── Conocimiento: la versión para quien no llega a la guía ──
        $this->addSql(
            'UPDATE agent_conocimiento SET contenido = ? WHERE nombre_interno = ?',
            [$this->conocimiento(), 'Estacionamiento (público)']
        );
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException(
            'Corrige un texto confuso y añade un teléfono real: volver atrás no aporta nada.'
        );
    }

    private function guiaPeldano0(): string
    {
        return <<<'TXT'
            Dos opciones, y ninguna de las dos es nuestra:

            - Justo AL FRENTE hay una playa de estacionamiento publica y GRATUITA. Funciona por orden
              de llegada: no se aparta sitio, ni nosotros ni nadie, porque no admite reservas. Si hay
              hueco, se ocupa.
            - A UNA CUADRA hay una cochera privada DE PAGO, la de Sapi.

            Ofrece las dos con confianza, es de lo que mas preguntan los que llegan en coche.

            Di siempre que la gratuita no es un area vigilada. Es honesto, y nos evita el reclamo si
            le pasa algo al vehiculo.
            TXT;
    }

    private function guiaPaso1(): string
    {
        return sprintf(
            <<<'TXT'
            Si pregunta el precio de la cochera o si habra sitio: nosotros no lo sabemos y no
            gestionamos ninguna de las dos, pero puede preguntarles el directamente. La cochera de
            Sapi atiende en el %s.

            Pasale el numero en vez de escalar: lo resuelve el mismo en un minuto y no hace falta
            que nadie del equipo lo averigue.

            De la playa gratuita no hay a quien preguntar: es publica y va por orden de llegada.
            TXT,
            self::TELEFONO
        );
    }

    private function conocimiento(): string
    {
        return sprintf(
            <<<'TXT'
            Hay dos opciones cerca, y ninguna es nuestra:

            - Justo al frente, una playa de estacionamiento publica y GRATUITA. Va por orden de
              llegada —no se aparta sitio, porque no admite reservas— y no es un area vigilada.
            - A una cuadra, una cochera privada DE PAGO, la de Sapi. Para consultar disponibilidad o
              precio se les escribe directamente al %s.

            Nosotros no gestionamos ninguna de las dos, asi que el precio y el sitio los confirma la
            cochera, no el equipo.
            TXT,
            self::TELEFONO
        );
    }
}
