<?php

declare(strict_types=1);

namespace App\Message\Form\Type;

use App\Entity\Maestro\MaestroIdioma;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Formulario para los ítems del array 'header' de WhatsApp Meta.
 * Hereda la lógica visual de idiomas (banderas) e incorpora el formato de Meta.
 */
class WhatsappMetaHeaderType extends AbstractType
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager
    ) {}

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        // 1. Obtener idiomas activos
        $idiomas = $this->entityManager->getRepository(MaestroIdioma::class)
            ->createQueryBuilder('i')
            ->where('i.prioridad > :min')
            ->setParameter('min', 0)
            ->orderBy('i.prioridad', 'DESC')
            ->addOrderBy('i.nombre', 'ASC')
            ->getQuery()
            ->getResult();

        $choices = [];
        foreach ($idiomas as $idioma) {
            $flag = $idioma->getBandera() ?? '🏳️';
            $label = $flag . ' ' . ucfirst($idioma->getNombre());
            $choices[$label] = $idioma->getId();
        }

        // =====================================================================
        // CAMPOS DEL FORMULARIO
        // =====================================================================

        $builder->add('language', ChoiceType::class, [
            'label' => false,
            'choices' => $choices,
            'attr' => ['class' => 'form-select-sm'],
            'row_attr' => ['class' => 'col-md-3 mb-2'],
        ]);

        $builder->add('format', ChoiceType::class, [
            'label' => false,
            'choices' => [
                'Texto Plano' => 'TEXT',
                'Imagen' => 'IMAGE',
                'Video' => 'VIDEO',
                'Documento' => 'DOCUMENT',
            ],
            'attr' => [
                'class' => 'form-select-sm',
                // Hacerlo readonly para que el usuario no cambie lo que Meta dictó
                // (Para que ChoiceType sea readonly en HTML, a veces se requiere CSS/JS extra,
                // pero 'style' => 'pointer-events: none;' es un buen truco visual).
                'style' => 'pointer-events: none; background-color: #e9ecef;'
            ],
            'row_attr' => ['class' => 'col-md-3 mb-2'],
        ]);

        $builder->add('content', TextType::class, [
            'label' => false,
            'required' => false, // Opcional porque si es IMAGE, no lleva texto
            'attr' => [
                'placeholder' => 'Ej: ¡Hola {{guest_name}}!',
                'class' => 'form-control-sm'
            ],
            'row_attr' => ['class' => 'col-md-12 mb-0'],
        ]);
    }

    public function buildView(FormView $view, FormInterface $form, array $options): void
    {
        // 2. Pasar el mapa de banderas a Twig
        $idiomas = $this->entityManager->getRepository(MaestroIdioma::class)
            ->createQueryBuilder('i')
            ->where('i.prioridad > 0')
            ->orderBy('i.prioridad', 'DESC')
            ->getQuery()
            ->getResult();

        $mapa = [];
        foreach ($idiomas as $idioma) {
            $mapa[$idioma->getId()] = [
                'flag' => $idioma->getBandera() ?? '🏳️',
                'name' => $idioma->getNombre(),
            ];
        }
        $view->vars['idiomas_config'] = $mapa;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => null,
            // IMPORTANTE: Definimos un bloque propio para renderizarlo distinto al Body
            'block_prefix' => 'whatsapp_meta_header_entry',
            'label' => false,
        ]);
    }
}