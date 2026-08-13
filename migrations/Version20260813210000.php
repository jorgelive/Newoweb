<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Estacionamiento, redactado por quien conoce el sitio.
 *
 * ### Qué cambia respecto de la versión anterior
 *
 * La anterior abría el aviso en seco —«no es un área vigilada»— y lo dejaba ahí. Es cierto, pero
 * dicho así asusta y no ayuda a decidir. Lo que de verdad pasa es que **mucha gente deja el coche
 * ahí de noche**, porque al costado hay una grifería abierta las 24 horas. El mismo hecho,
 * contextualizado, deja de ser una advertencia y pasa a ser información útil — y sigue siendo
 * igual de honesto, que era el motivo de decirlo.
 *
 * Y añade dos datos que faltaban: la cochera privada **se contrata el mismo día de la llegada**, y
 * el teléfono es del **propietario**, para coordinar disponibilidad.
 *
 * ### Se queda sin escalera, y está bien
 *
 * La versión anterior tenía un paso 1 para «no sabemos el precio, avisa al equipo». Con el
 * teléfono en la primera respuesta ese peldaño sobra: quien pregunta el precio ya sabe a quién
 * preguntárselo. No todos los temas quieren escalera — ver CLAUDE.md §Cómo se estructura el
 * contenido de la guía.
 */
final class Version20260813210000 extends AbstractMigration
{
    private const string TELEFONO = '+51 984 631 997';

    public function getDescription(): string
    {
        return 'Estacionamiento: la grifería 24 h como contexto del «no vigilado», y la cochera '
            . 'privada se contrata el mismo día.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            "UPDATE pms_guia_item SET agente_contenido = ?, agente_pasos = '[]' WHERE nombre_interno = ?",
            [$this->paraElAgente(), 'Estacionamiento (general)']
        );

        $this->addSql(
            'UPDATE agent_conocimiento SET contenido = ? WHERE nombre_interno = ?',
            [$this->paraElAgente(), 'Estacionamiento (público)']
        );
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException(
            'Mejora un texto con datos que sólo tiene el negocio: volver atrás no aporta nada.'
        );
    }

    /**
     * El mismo texto para la guía y para el conocimiento.
     *
     * Aquí no hay nada que dependa de la casita ni de la estancia, así que las dos superficies
     * pueden decir exactamente lo mismo — y conviene que lo digan, porque es lo que evita que se
     * separen. Distinto de las duchas, donde cada casa tiene su grifo.
     */
    private function paraElAgente(): string
    {
        return sprintf(
            <<<'TXT'
            Justo frente a la casa hay un estacionamiento publico. No es vigilado, pero mucha gente
            deja ahi su vehiculo durante la noche: al costado hay una grifería que trabaja las 24
            horas.

            A una cuadra hay ademas una cochera privada. Se puede contratar el mismo dia de la
            llegada, y si quiere coordinar la disponibilidad con el propietario, este es su numero:
            %s.

            Ofrece las dos con confianza: es de lo que mas preguntan los que llegan en coche.
            Ninguna de las dos es nuestra, asi que el precio y el sitio los confirma la cochera, no
            el equipo.
            TXT,
            self::TELEFONO
        );
    }
}
