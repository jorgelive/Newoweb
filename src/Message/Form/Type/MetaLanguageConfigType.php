<?php

declare(strict_types=1);

namespace App\Message\Form\Type;

use App\Entity\Maestro\MaestroIdioma;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class MetaLanguageConfigType extends AbstractType
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager
    ) {}

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        // Cargamos los idiomas activos desde el maestro
        $idiomas = $this->entityManager->getRepository(MaestroIdioma::class)
            ->createQueryBuilder('i')
            ->where('i.prioridad > 0')
            ->orderBy('i.prioridad', 'DESC')
            ->getQuery()
            ->getResult();

        $choices = [];
        foreach ($idiomas as $idioma) {
            $label = ($idioma->getBandera() ?? '🏳️') . ' ' . ucfirst($idioma->getNombre());
            $choices[$label] = $idioma->getId();
        }

        $builder
            ->add('language', ChoiceType::class, [
                'label' => 'Idioma',
                'choices' => $choices,
                'attr' => ['class' => 'form-select-sm'],
                'row_attr' => ['class' => 'col-md-3 mb-2'],
            ])
            ->add('meta_template_id', TextType::class, [
                'label' => 'ID exacto en Meta',
                'attr' => ['placeholder' => 'Ej: welcome_en_v2'],
                'row_attr' => ['class' => 'col-md-6 mb-2'],
            ])
            ->add('status', ChoiceType::class, [
                'label' => 'Estado',
                'choices' => [
                    'Aprobado' => 'APPROVED',
                    'Pendiente' => 'PENDING',
                    'Rechazado' => 'REJECTED'
                ],
                'attr' => ['class' => 'form-select-sm'],
                'row_attr' => ['class' => 'col-md-3 mb-2'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => null,
            'row_attr' => ['class' => 'row align-items-end mb-2']
        ]);
    }
}