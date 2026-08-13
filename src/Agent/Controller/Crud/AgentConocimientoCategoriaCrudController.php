<?php

declare(strict_types=1);

namespace App\Agent\Controller\Crud;

use App\Agent\Entity\AgentConocimientoCategoria;
use App\Panel\Controller\Crud\BaseCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

/**
 * Los temas del conocimiento: la primera fase de la consulta.
 *
 * Es una tabla y no un enum justo para esto: se alimenta desde aquí, con el tiempo y sin
 * despliegues. El día que haya que añadir «reclamos» a las nueve de la noche, se añade.
 */
class AgentConocimientoCategoriaCrudController extends BaseCrudController
{
    public static function getEntityFqcn(): string
    {
        return AgentConocimientoCategoria::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Tema de conocimiento')
            ->setEntityLabelInPlural('Temas de conocimiento')
            ->setDefaultSort(['orden' => 'ASC', 'nombre' => 'ASC'])
            ->setHelp('index', 'Son los grupos que el agente ve PRIMERO para decidir de qué va una '
                . 'pregunta. Cuantos menos y más distintos entre sí, mejor acierta: si dos temas '
                . 'se solapan, acabará entrando en el equivocado.');
    }

    public function configureFields(string $pageName): iterable
    {
        yield TextField::new('id', 'Id')
            ->setHelp('En minúsculas y sin espacios: <code>pagos</code>, <code>llegada</code>. '
                . 'Sale en el prompt y en los registros, así que se escribe para que se entienda '
                . 'al leerlo. No se puede cambiar después sin dejar huérfano lo que cuelga.')
            ->setColumns(3);

        yield TextField::new('nombre', 'Nombre')
            ->setHelp('Como se le presenta al agente: «Pagos y facturación».')
            ->setColumns(5);

        yield TextField::new('pista', 'Pista para el agente')
            ->setHelp('Una línea, sólo si hace falta distinguirlo de otro tema parecido. Si el '
                . 'nombre ya lo dice todo, déjalo vacío: cada palabra aquí se paga en cada turno.')
            ->setColumns(4);

        yield IntegerField::new('orden', 'Orden')
            ->setHelp('En qué orden los ve el agente. Los primeros pesan un poco más cuando duda, '
                . 'así que pon delante los que más se preguntan. De 10 en 10, para poder colar '
                . 'uno en medio sin renumerar.')
            ->setColumns(2);

        yield BooleanField::new('activa', 'Activo')
            ->setHelp('Apagarlo lo saca de la lista que ve el agente, sin borrar sus respuestas.')
            ->renderAsSwitch(true);
    }
}
