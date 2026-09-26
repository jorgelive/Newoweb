<?php

declare(strict_types=1);

namespace App\Panel\Form\Type;

use App\Entity\Maestro\MaestroIdioma;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\OptionsResolver\OptionsResolver;

class TranslationHtmlType extends AbstractType
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager
    ) {}

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var list<MaestroIdioma> $idiomas */
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
            $label = $flag . ' ' . ucfirst($idioma->getNombre() ?? (string) $idioma->getId());   // sin nombre, su código
            $choices[$label] = $idioma->getId();
        }

        $builder->add('language', ChoiceType::class, [
            'label' => false,
            'choices' => $choices,
            'attr' => ['class' => 'form-select-sm'],
            'row_attr' => ['class' => 'col-md-12 mb-2'],
        ]);

        $builder->add('content', TextareaType::class, [
            'label' => false,
            'attr' => [
                'data-controller' => 'panel--tinymce', // Tu controlador Stimulus
                'rows' => 10,
                'placeholder' => 'Contenido HTML...'
            ],
            'row_attr' => ['class' => 'col-md-12'],
        ]);
    }

    public function buildView(FormView $view, FormInterface $form, array $options): void
    {
        // Reutilizamos la misma lógica de banderas
        /** @var list<MaestroIdioma> $idiomas */
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
            // ✅ Usamos el MISMO prefijo para compartir la plantilla Twig
            'block_prefix' => 'pms_translation_entry',
            'label' => false,
        ]);
    }
}