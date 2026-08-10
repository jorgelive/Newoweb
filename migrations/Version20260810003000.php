<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Los medios de cobro del establecimiento virtual: Yape, Plin, cuentas, Western Union.
 *
 * Hasta ahora el número de cuenta y el de Yape no vivían en ninguna parte del sistema —ni en
 * la guía, ni en el establecimiento, ni en configuración—. La consecuencia se vio en la
 * reserva V4JE5Q: el agente, al que le preguntaron cuatro veces cómo pagar, se inventó un
 * número de Yape y una URL de pago. Ver docs/Mensajeria.md §16.
 *
 * Nace vacío a propósito y NO se siembra ningún dato aquí: los números de cuenta los teclea el
 * equipo desde el panel, donde se ven y se corrigen. Una cuenta bancaria escrita en una
 * migración es una cuenta que nadie vuelve a mirar.
 *
 * Mientras esté vacío, {@see \App\Agent\Skill\Pms\ConsultarMediosPagoSkill} devuelve la
 * instrucción de escalar en vez de una lista vacía, así que el hueco no vuelve a rellenarse
 * con imaginación.
 */
final class Version20260810003000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Añade medios_cobro (JSON) a pms_establecimiento_virtual.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE pms_establecimiento_virtual ADD medios_cobro JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE pms_establecimiento_virtual DROP medios_cobro');
    }
}
