<?php

declare(strict_types=1);

namespace App\Agent\Controller\Crud;

use App\Agent\Conversation\PerfilConversacion;
use App\Agent\Entity\AgentConocimiento;
use App\Panel\Controller\Crud\BaseCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

/**
 * Las respuestas escritas a lo que preguntan todo el rato.
 *
 * Esta pantalla es la que hace que el agente escale menos: cada fila que se añade aquí es una
 * interrupción menos a una persona para repetir lo de siempre.
 */
class AgentConocimientoCrudController extends BaseCrudController
{
    public static function getEntityFqcn(): string
    {
        return AgentConocimiento::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Respuesta del agente')
            ->setEntityLabelInPlural('Conocimiento del agente')
            ->setDefaultSort(['createdAt' => 'DESC'])
            ->setHelp('index', 'Esto es lo ÚLTIMO que mira el agente antes de escalar por no '
                . 'saber. Cada respuesta que añadas aquí es una interrupción menos al equipo. '
                . 'No sirve para lo que cambia —fechas, saldos, disponibilidad—: eso lo consultan '
                . 'las herramientas y siempre estará más al día que un texto escrito a mano.');
    }

    public function configureFields(string $pageName): iterable
    {
        yield AssociationField::new('categoria', 'Tema')
            ->setHelp('El agente elige primero el tema y sólo después mira las respuestas de '
                . 'dentro. Si no encuentras uno que encaje, crea el tema antes.')
            ->setColumns(4);

        yield TextField::new('nombreInterno', 'Nombre interno')
            ->setHelp('Para encontrarlo tú en esta lista. El agente NO lo ve.')
            ->setColumns(8);

        yield TextareaField::new('etiquetas', 'Cómo lo pregunta la gente')
            ->setHelp('Las palabras con las que el agente lo reconoce, separadas por comas: '
                . '<code>estacionamiento, parking, dónde dejo el auto, cochera</code>. '
                . '⚠️ Escríbelas como pregunta el HUÉSPED, no como lo llamamos nosotros: es lo '
                . 'único de esta ficha que el agente usa para elegir, y si sólo pones nuestro '
                . 'vocabulario no lo encontrará cuando más falta hace.')
            ->setNumOfRows(3);

        yield TextareaField::new('contenido', 'Lo que se dirá')
            ->setHelp('Ya redactado y corto. El agente lo traduce y ajusta el tono, pero no '
                . 'debería tener que inventar nada: si hace falta inventar, es que falta texto.')
            ->setNumOfRows(6);

        yield ChoiceField::new('dominios', 'Negocios')
            ->setChoices(['Alojamiento' => 'hotelero', 'Turismo' => 'turistico'])
            ->allowMultipleChoices()
            ->setHelp('<strong>Vacío = vale para los dos.</strong> Marca sólo si de verdad es de '
                . 'uno: el horario de la oficina o los medios de pago son de los dos.')
            ->setColumns(6);

        yield ChoiceField::new('perfiles', 'A quién se le puede contar')
            ->setChoices(array_combine(
                array_map(static fn (PerfilConversacion $p): string => ucfirst($p->value), PerfilConversacion::cases()),
                array_map(static fn (PerfilConversacion $p): string => $p->value, PerfilConversacion::cases())
            ))
            ->allowMultipleChoices()
            ->setHelp('<strong>Vacío = a todos.</strong> Marca a quién SÍ, sólo cuando la '
                . 'respuesta no sea para cualquiera: un procedimiento interno o un código van '
                . 'sólo para el equipo.')
            ->setColumns(6);

        yield BooleanField::new('requiereHumano', 'Además, avisar al equipo')
            ->setHelp('Para lo que se puede explicar pero lo resuelve una persona: el agente '
                . 'contesta esto Y escala, en vez de elegir entre las dos cosas.')
            ->renderAsSwitch(true)
            ->setColumns(6);

        yield BooleanField::new('activo', 'Activo')->renderAsSwitch(true)->setColumns(6);
    }
}
