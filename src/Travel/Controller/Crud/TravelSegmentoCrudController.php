<?php

declare(strict_types=1);

namespace App\Travel\Controller\Crud;

use App\Panel\Controller\Crud\BaseCrudController;
use App\Panel\Form\Type\TranslationHtmlType;
use App\Panel\Form\Type\TranslationTextType;
use App\Travel\Entity\TravelSegmento;
use App\Travel\Entity\TravelSegmentoComponente;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\CollectionField;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

class TravelSegmentoCrudController extends BaseCrudController
{
    public static function getEntityFqcn(): string
    {
        return TravelSegmento::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->showEntityActionsInlined()
            ->setEntityLabelInSingular('Segmento')
            ->setEntityLabelInPlural('Segmentos de itinerario');
    }

    /**
    * Sobrescribimos el método de eliminación para capturar el error de llave foránea
    * y evitar el Error 500 en producción.
    */
    public function deleteEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        try {
            // Intentamos eliminar el segmento de forma normal
            $entityManager->remove($entityInstance);
            $entityManager->flush();

        } catch (ForeignKeyConstraintViolationException $e) {
            // Si MySQL nos bloquea porque el segmento está en un itinerario,
            // mostramos un mensaje amigable y abortamos la eliminación.
            $this->addFlash(
                'danger',
                '⛔ <strong>Acción denegada:</strong> No puedes eliminar este segmento porque está siendo utilizado en uno o más Itinerarios. Si realmente deseas borrarlo, primero debes quitarlo de las plantillas.'
            );

            // Refrescamos la entidad para que Doctrine no se quede en un estado inconsistente
            $entityManager->refresh($entityInstance);
        }
    }

    public function configureFields(string $pageName): iterable
    {
        yield FormField::addPanel('Configuración de la Pieza')->setIcon('fa fa-puzzle-piece');

        // 🔥 ACTUALIZADO: Select2 Múltiple para permitir reciclar en múltiples pools
        yield AssociationField::new('servicios', 'Pools')
            ->setFormTypeOptions([
                'by_reference' => false,
                'multiple' => true,
            ])
            ->setHelp('Pools de Servicios (Contenedores) donde estará disponible.')
            ->setColumns(6);

        yield TextField::new('nombreInterno', 'Nombre Administrativo (ID)')
            ->setHelp('Ej: "MM - Visita Ciudadela Circuito 2"')
            ->setColumns(6);

        yield BooleanField::new('ejecutarTraduccion', 'Traducir Automáticamente')->onlyOnForms()->setColumns(6);
        yield BooleanField::new('sobreescribirTraduccion', 'Sobrescribir Existentes')->onlyOnForms()->setColumns(6);

        yield FormField::addPanel('Contenido Narrativo')->setIcon('fa fa-pen-fancy');

        yield CollectionField::new('titulo', 'Título del Segmento')
            ->setEntryType(TranslationTextType::class)
            ->setColumns(12);

        yield CollectionField::new('contenido', 'Cuerpo del Relato')
            ->setEntryType(TranslationHtmlType::class)
            ->setColumns(12);

        yield FormField::addPanel('Logística y Multimedia')->setIcon('fa fa-cogs');

        // 🔥 LÍNEA APROX: 115 - VISTA FORMULARIOS: Gestión de la Colección (Campo Real)
        yield CollectionField::new('segmentoComponentes', 'Componentes Logísticos Vinculados')
            ->hideOnIndex()
            ->useEntryCrudForm(TravelSegmentoComponenteCrudController::class)
            ->setFormTypeOptions([
                'by_reference' => false,
                'prototype'    => true,
            ])
            ->setFormTypeOption('prototype_data', new TravelSegmentoComponente())
            ->setHelp('Configura qué componentes se inyectarán automáticamente en el itinerario al usar este párrafo.')
            ->setColumns(12);

        yield TextField::new('virtualLogistica', 'Logística Inyectada')
            ->onlyOnIndex()
            ->formatValue(function ($value, $entity) {
                // Leemos la colección directamente desde la entidad, esquivando a EasyAdmin
                $coleccion = $entity->getSegmentoComponentes();

                if ($coleccion->isEmpty()) {
                    return '<span class="badge bg-light text-muted border">Sin logística</span>';
                }

                $html = '<div class="d-flex flex-column gap-1" style="font-size: 11px; min-width: 250px;">';

                foreach ($coleccion as $sc) {
                    $compName = $sc->getComponente() ? htmlspecialchars((string) $sc->getComponente()) : 'N/A';
                    $hora = $sc->getHora() ? $sc->getHora()->format('H:i') : 'Horario BD';
                    $ctx = $sc->getItinerarioContexto() ? htmlspecialchars($sc->getItinerarioContexto()->getNombreInterno()) : 'Global';

                    // Colores visuales
                    $colorCtx = $sc->getItinerarioContexto() ? 'text-primary' : 'text-success';
                    $iconCtx = $sc->getItinerarioContexto() ? 'fa-filter' : 'fa-globe';

                    $html .= sprintf(
                        '<div class="p-1 border rounded bg-white shadow-sm">
                            <strong class="d-block text-truncate mb-1" style="max-width: 280px;" title="%s">%s</strong>
                            <span class="text-muted"><i class="far fa-clock"></i> %s</span> <span class="mx-1 text-muted">|</span> 
                            <span class="%s fw-bold" title="Contexto de Plantilla"><i class="fas %s"></i> %s</span>
                        </div>',
                        $compName, $compName, $hora, $colorCtx, $iconCtx, $ctx
                    );
                }

                $html .= '</div>';

                return $html;
            })
            ->renderAsHtml();
        yield CollectionField::new('imagenes', 'Galería de Fotos')
            ->useEntryCrudForm(TravelSegmentoImagenCrudController::class)
            ->setColumns(12);
    }
}