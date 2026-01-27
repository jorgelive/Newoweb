<?php

namespace App\Panel\Field;

use EasyCorp\Bundle\EasyAdminBundle\Contracts\Field\FieldInterface;
use EasyCorp\Bundle\EasyAdminBundle\Field\FieldTrait; // <--- Aquí están las funciones mágicas
use Symfony\Component\Form\Extension\Core\Type\TextType;

final class LiipImageField implements FieldInterface
{
    // Este Trait inyecta todos los métodos estándar (setProperty, setLabel, etc.)
    use FieldTrait;

    // Definimos el nombre de nuestra opción personalizada
    public const OPTION_LIIP_FILTER = 'liip_filter';

    /**
     * @param string $propertyName El nombre de la propiedad en la entidad (ej: 'documentoUrl')
     * @param string|null $label La etiqueta visual
     */
    public static function new(string $propertyName, ?string $label = null): self
    {
        return (new self())
            ->setProperty($propertyName)
            ->setLabel($label)

            // 1. Asignamos nuestro Template Twig personalizado
            ->setTemplatePath('panel/field/liip_image.html.twig')

            // 2. Tipo de formulario (TextType porque pasamos la URL como string, o null si solo es lectura)
            // Normalmente ocultarás este campo en formularios con ->hideOnForm()
            ->setFormType(TextType::class)

            // 3. Clase CSS para identificarlo
            ->addCssClass('field-liip-image')

            // 4. Opción por defecto: Usar el filtro 'pms_thumb_admin'
            ->setCustomOption(self::OPTION_LIIP_FILTER, 'pms_thumb_admin');
    }

    /**
     * Método público para permitir cambiar el filtro desde el Controlador.
     * Uso: LiipImageField::new('foto')->setLiipFilter('otro_filtro')
     */
    public function setLiipFilter(string $filterName): self
    {
        $this->setCustomOption(self::OPTION_LIIP_FILTER, $filterName);
        return $this;
    }
}