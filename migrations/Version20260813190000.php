<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * El equipo también recibe las respuestas públicas del conocimiento.
 *
 * ### Qué estaba mal
 *
 * Las cinco fichas que duplican a propósito un tema de la guía se acotaron a `prospecto` e
 * `interesado`, con el razonamiento correcto —el huésped debe recibir la ficha de SU casita, que
 * es mejor— y una consecuencia que no se vio: **el equipo se quedó viendo menos que un
 * desconocido**.
 *
 * Se descubrió probando el bot desde el WhatsApp interno:
 *
 * ```
 *   «Dan frazadas adicionales en los departamentos»  → correcto (esa ficha va sin acotar)
 *   «Hay cochera»                                    → «No tenemos cochera propia.»
 * ```
 *
 * La segunda es una negativa seca donde había dos opciones que ofrecer —playa pública gratuita
 * enfrente, cochera privada de pago a una cuadra—. El agente no tenía la ficha porque quien
 * preguntaba era del equipo, y tiró de memoria.
 *
 * Y quien prueba el bot es, precisamente, el equipo.
 *
 * ### La regla correcta
 *
 * La acotación no es «sólo para prospectos»: es **«para todos menos el huésped»**. Él es el único
 * que sobra, porque para él existe algo mejor. Los demás la necesitan.
 *
 * Es dato plano en una columna JSON, sin listeners de por medio, así que va por migración — ver
 * CLAUDE.md §Qué entra por migración y qué tiene que entrar por comando.
 */
final class Version20260813190000 extends AbstractMigration
{
    private const string PUBLICO = '["prospecto", "interesado", "personal", "colaborador"]';

    private const string ANTERIOR = '["prospecto", "interesado"]';

    public function getDescription(): string
    {
        return 'Las fichas públicas del conocimiento dejan de excluir al equipo: la acotación es '
            . '«todos menos el huésped», no «sólo prospectos».';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'UPDATE agent_conocimiento SET perfiles = ? WHERE CAST(perfiles AS CHAR) = ?',
            [self::PUBLICO, self::ANTERIOR]
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql(
            'UPDATE agent_conocimiento SET perfiles = ? WHERE CAST(perfiles AS CHAR) = ?',
            [self::ANTERIOR, self::PUBLICO]
        );
    }
}
