<?php

declare(strict_types=1);

namespace App\Travel\Controller\Crud;

use App\Panel\Controller\Crud\BaseCrudController;
use App\Travel\Entity\TravelItinerarioSegmentoRel;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

/**
 * Gestiona la selección y orden cronológico de los segmentos dentro de una plantilla.
 */
class TravelItinerarioSegmentoRelCrudController extends BaseCrudController
{
    public static function getEntityFqcn(): string
    {
        return TravelItinerarioSegmentoRel::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->showEntityActionsInlined();
    }

    public function configureFields(string $pageName): iterable
    {
        // 🔥 EL HACK MAESTRO: Imprimimos el ID deshabilitado y lo ocultamos con CSS
        yield TextField::new('id')
            ->setDisabled(true) // Para que Symfony no intente guardar este campo y lanzar error
            ->onlyOnForms()
            ->setFormTypeOptions([
                'attr' => ['class' => 'rel-id-target'], // Clase ancla para nuestro Javascript
                'row_attr' => ['class' => 'd-none'] // d-none oculta toda la fila visualmente
            ]);
        yield AssociationField::new('segmento', 'Segmento del Pool')
            ->setHelp('Selecciona un párrafo narrativo del pool de este servicio.')
            ->setColumns(6);

        yield IntegerField::new('dia', 'Día Relativo')
            ->setFormTypeOptions([
                'empty_data' => '1',  // 👈 esto es lo que resuelve el null
            ])
            ->setHelp('Ej: 1 (Para el primer día)')
            ->setColumns(3);

        yield IntegerField::new('orden', 'Orden de Aparición')
            ->setHelp('Define la secuencia dentro del mismo día.')
            ->setFormTypeOptions([
                'empty_data' => '1',  // 👈 esto es lo que resuelve el null
            ])
            ->setColumns(3)->setFormTypeOptions([
                'row_attr' => [
                    // Con esto, Stimulus se "conecta" individualmente a cada fila renderizada
                    'data-controller' => 'panel--travel-segmento-componente-modal'
                ]
            ]);
    }
}