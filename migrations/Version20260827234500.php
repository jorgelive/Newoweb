<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Los teléfonos dejan de estar copiados en la guía: la única copia es la del establecimiento.
 *
 * El ítem «Horario solicitudes (general)» llevaba escritos a mano `+51 961 281 953` y
 * `+51 958 191 965`, que son exactamente `pms_establecimiento.telefono_atencion` y
 * `telefono_yape`. Dos copias del mismo dato en sitios que nadie sincroniza: el día que cambie
 * uno, la guía seguiría dando el viejo sin que nada falle.
 *
 * Peor que la duplicidad era el ALCANCE: dentro de la ficha, los números sólo llegaban si el
 * índice de temas la elegía por sus términos («horario de atención», «a qué hora atienden»).
 * El 27/08/2026 una huésped escribió «acabamos de llegar» —que no casa con ninguno—, el modelo
 * se quedó sin ningún teléfono que ofrecer y se inventó que alguien iría a recibirla.
 * `ConsultarCodigosSkill` los devuelve ahora en `contacto` sin depender de ninguna palabra.
 *
 * ⚠️ **Queda un puntero, y es obligatorio.** Borrar el dato sin decir que existe hace que el
 * modelo lo NIEGUE —está probado con el depósito de garantía, ver `CLAUDE.md`—: quitar los
 * números a secas convertiría «¿tienen teléfono?» en «no tenemos». El texto no dice cuáles son;
 * dice dónde pedirlos.
 *
 * Va por SQL y no por comando porque `agente_contenido` es texto plano que sólo lee el agente:
 * no lo toca `AutoTranslationEventListener` ni ningún listener de coherencia.
 */
final class Version20260827234500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Quita los teléfonos copiados a mano de la guía y deja el puntero a consultar_codigos';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            UPDATE pms_guia_item
            SET agente_contenido = :contenido
            WHERE nombre_interno = :nombre
            SQL, [
            'nombre' => 'Horario solicitudes (general)',
            'contenido' => <<<'TXT'
                Horario de atencion: 9:00 a.m. a 9:00 p.m.

                Si necesita papel higienico, jabon liquido u otros suministros, que avise con un dia de
                anticipacion para poder reponerlos.

                SI PIDE UN TELEFONO: los numeros existen, pero NO estan escritos aqui. Te los da
                consultar_codigos en el campo «contacto» — pidelos ahi y pasalos tal cual. Uno de los
                dos es ademas el numero del Yape. No hay copia en esta ficha a proposito: envejeceria
                el dia que cambien.
                TXT,
        ]);
    }

    /**
     * ⚠️ El `down()` reescribe los dos números **literales**, tal como estaban el 27/08/2026.
     *
     * Si para cuando alguien lo ejecute los teléfonos del establecimiento ya han cambiado, esto
     * devuelve los viejos a la ficha presentándolos como vigentes — y ahí sí volvería a haber
     * dos copias, discrepando. Es inherente a revertir contenido con literales: si hay que
     * volver atrás, comprobar antes `pms_establecimiento.telefono_atencion` y `telefono_yape`.
     */
    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            UPDATE pms_guia_item
            SET agente_contenido = :contenido
            WHERE nombre_interno = :nombre
            SQL, [
            'nombre' => 'Horario solicitudes (general)',
            'contenido' => <<<'TXT'
                Horario de atencion: 9:00 a.m. a 9:00 p.m.

                Si necesita papel higienico, jabon liquido u otros suministros, que avise con un dia de
                anticipacion para poder reponerlos.

                Telefonos, si los pide: +51 961 281 953 y +51 958 191 965. Por los dos contestamos. El
                segundo es ademas el numero del Yape, asi que si pregunta si es el mismo, la respuesta es que si.
                TXT,
        ]);
    }
}
