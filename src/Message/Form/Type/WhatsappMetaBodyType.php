<?php

declare(strict_types=1);

namespace App\Message\Form\Type;

use App\Entity\Maestro\MaestroIdioma;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * Formulario para los ítems del array 'body' de WhatsApp Meta.
 * Hereda la lógica visual de idiomas (banderas) e incorpora el estado de aprobación.
 */
class WhatsappMetaBodyType extends AbstractType
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
            // Ocupa 3 columnas
            'row_attr' => ['class' => 'col-md-3 mb-2'],
        ]);

        $builder->add('status', ChoiceType::class, [
            'label' => false, // Ocultamos el label para mantener el estilo inline
            'choices' => [
                '🟢 Aprobada' => 'APPROVED',
                '🟡 Pendiente' => 'PENDING',
                '🔴 Rechazada' => 'REJECTED',
            ],
            'attr' => ['class' => 'form-select-sm'],
            // Ocupa 3 columnas (se pone al lado del idioma)
            'row_attr' => ['class' => 'col-md-3 mb-2'],
        ]);

        $builder->add('content', TextareaType::class, [
            'label' => false,
            'attr' => [
                'placeholder' => 'Escribe el mensaje base (soporta variables {{guest_name}})...',
                'rows' => 4,
            ],
            // Ocupa la fila completa debajo de los selectores
            'row_attr' => ['class' => 'col-md-12 mb-0'],
            'constraints' => [new NotBlank(['message' => 'El contenido es obligatorio.'])],
            'required' => true,
        ]);
    }

    public function buildView(FormView $view, FormInterface $form, array $options): void
    {
        // 2. Pasar el mapa de banderas a Twig (Igual que en tu componente original)
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
            // CRÍTICO: null para que devuelva un array asociativo limpio al JSON
            'data_class' => null,

            // Prefijo único por si decides crear un bloque Twig específico para renderizarlo
            'block_prefix' => 'whatsapp_meta_body_entry',
            'label' => false,
        ]);
    }
}