<?php

declare(strict_types=1);

namespace App\Travel\Controller\Crud;

use App\Panel\Controller\Crud\BaseCrudController;
use App\Panel\Form\Type\TranslationHtmlType;
use App\Panel\Form\Type\TranslationTextType;
use App\Travel\Entity\TravelNota;
use App\Travel\Enum\NotaTipoEnum;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\CollectionField;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\EntityFilter;

class TravelNotaCrudController extends BaseCrudController
{
    public static function getEntityFqcn(): string
    {
        return TravelNota::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->showEntityActionsInlined()
            ->setEntityLabelInSingular('Nota')
            ->setEntityLabelInPlural('Notas de itinerario');
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add(EntityFilter::new('segmentos', 'Segmento'));
    }

    public function configureFields(string $pageName): iterable
    {
        yield FormField::addPanel('Configuración de la Nota')->setIcon('fa fa-info-circle');

        yield TextField::new('nombreInterno', 'Nombre Administrativo (ID)')
            ->setHelp('Ej: "Política de Cancelación Inca Rail" o "Historia Balcones de Cusco"')
            ->setColumns(6);

        yield ChoiceField::new('tipo', 'Tipo de Nota / Categoría')
            ->setChoices(array_reduce(NotaTipoEnum::cases(), static fn ($c, $e) => $c + [$e->name => $e], []))
            ->formatValue(static fn ($value) => $value instanceof NotaTipoEnum ? $value->value : $value)
            ->setHelp('Define si es una Historia, Tip, Alerta o Política.')
            ->setColumns(6);

        yield FormField::addPanel('Contenido Multilingüe')->setIcon('fa fa-language');

        yield BooleanField::new('ejecutarTraduccion', 'Traducir Automáticamente')->onlyOnForms()->setColumns(6);
        yield BooleanField::new('sobreescribirTraduccion', 'Sobrescribir Existentes')->onlyOnForms()->setColumns(6);

        yield CollectionField::new('titulo', 'Título Visible al Cliente')
            ->setEntryType(TranslationTextType::class)
            ->onlyOnForms()
            ->setColumns(12);

        yield CollectionField::new('contenido', 'Cuerpo del Texto')
            ->setEntryType(TranslationHtmlType::class)
            ->onlyOnForms()
            ->setColumns(12);

        yield TextField::new('virtualTituloEs', 'Título (ES)')
            ->onlyOnIndex();

        yield TextField::new('virtualContenidoEs', 'Contenido (ES)')
            ->onlyOnIndex();

        // 🔥 Segmentos donde está vinculada esta nota (solo listado)
        yield TextField::new('virtualSegmentos', 'Usada en Segmentos')
            ->onlyOnIndex()
            ->formatValue(static function ($value, $entity) {
                $segmentos = $entity->getSegmentos();
                if ($segmentos->isEmpty()) {
                    return '<span class="badge bg-light text-muted border">Sin segmentos vinculados</span>';
                }

                $html = '<ul style="max-height: 160px; overflow-y: auto; text-align: left; min-width: 220px; margin: 0; padding: 0 5px 0 0; list-style: none;">';
                foreach ($segmentos as $segmento) {
                    $nombre = htmlspecialchars((string) $segmento);
                    $html .= sprintf(
                        '<li class="px-2 py-1 mb-1 bg-white border rounded small text-truncate" title="%s"><i class="fas fa-map-signs text-primary" style="font-size: 0.8em; margin-right: 4px;"></i> %s</li>',
                        $nombre,
                        $nombre
                    );
                }
                $html .= '</ul>';
                return $html;
            })
            ->renderAsHtml();
    }
}