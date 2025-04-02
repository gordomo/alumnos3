<?php

namespace App\Form;

use App\Entity\Alumno;
use App\Entity\Curso;
use Doctrine\ORM\EntityRepository;
use PhpOffice\PhpSpreadsheet\Calculation\TextData\Text;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class AlumnoType extends AbstractType
{
    public $alumno;
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $this->alumno = $options['data'];
        $isEdit = $options['is_edit'];
        $instituto = $options['instituto'];
        $builder
            ->add('nombre', TextType::class, ['attr' => ['class' => 'form-control'], 'label_attr' => ['class' => 'form-label required'], ])
            ->add('apellido', TextType::class, ['attr' => ['class' => 'form-control'], 'label_attr' => ['class' => 'form-label required'], ])
            ->add('email', EmailType::class, ['attr' => ['class' => 'form-control'], 'label_attr' => ['class' => 'form-label required'], ])
            ->add('dni', TextType::class, ['attr' => ['class' => 'form-control'], 'label_attr' => ['class' => 'form-label required'], ])
            ->add('f_nac', DateType::class, ['widget' => 'single_text', 'attr' => ['class' => 'form-control'], 'label_attr' => ['class' => 'form-label required'], ])
            ->add('l_nac', TextType::class, ['attr' => ['class' => 'form-control'], 'required' => false, 'label_attr' => ['class' => 'form-label '], ])
            ->add('telefono_fijo', TextType::class, ['attr' => ['class' => 'form-control'], 'required' => false, 'label_attr' => ['class' => 'form-label '], ])
            ->add('celular', TextType::class, ['attr' => ['class' => 'form-control'], 'required' => false, 'label_attr' => ['class' => 'form-label '], ])
            ->add('contacto_emergencia', TextType::class, ['attr' => ['class' => 'form-control'], 'required' => false, 'label_attr' => ['class' => 'form-label '], ])
            ->add('n_tutor', TextType::class, ['attr' => ['class' => 'form-control'], 'required' => false, 'label_attr' => ['class' => 'form-label '], ])
            ->add('t_tutor', TextType::class, ['attr' => ['class' => 'form-control'], 'required' => false, 'label_attr' => ['class' => 'form-label '], ])
            ->add('corre_tutor', EmailType::class, ['attr' => ['class' => 'form-control'], 'required' => false, 'label_attr' => ['class' => 'form-label '], ])
            ->add('dni_tutor', TextType::class, ['attr' => ['class' => 'form-control'], 'required' => false, 'label_attr' => ['class' => 'form-label '], ])
            ->add('escuela', TextType::class, ['attr' => ['class' => 'form-control'], 'required' => false, 'label_attr' => ['class' => 'form-label '], ])
            ->add('extras', TextType::class, ['attr' => ['class' => 'form-control'], 'required' => false, 'label_attr' => ['class' => 'form-label '], ])
            ->add('g_sanguineo', TextType::class, ['attr' => ['class' => 'form-control'], 'required' => false, 'label_attr' => ['class' => 'form-label '], ])
            ->add('enfermedad', TextType::class, ['attr' => ['class' => 'form-control'], 'required' => false, 'label_attr' => ['class' => 'form-label '], ])
            ->add('alergico', TextType::class, ['attr' => ['class' => 'form-control'], 'required' => false, 'label_attr' => ['class' => 'form-label '], ])
            ->add('como_conociste', TextType::class, ['attr' => ['class' => 'form-control'], 'required' => false, 'label_attr' => ['class' => 'form-label '], ])
            ->add('activo', ChoiceType::class, ['attr' => ['class' => 'form-control'], 'label_attr' => ['class' => 'form-label required'], 'choices'  => [
                'Si' => 1,
                'No' => 0,
                ]
            ])
            ->add('medicacion', TextType::class, ['attr' => ['class' => 'form-control'], 'required' => false, 'label_attr' => ['class' => 'form-label '], ])
            ->add('hermanos', EntityType::class, [
                'class' => Alumno::class,
                'label_attr' => ['class' => 'form-label'], 
                'choice_label' => function(Alumno $alumno) {
                    return $alumno->getNombre() . ' ' . $alumno->getApellido();
                },
                'query_builder' => function (EntityRepository $er) use ($instituto, $isEdit) {
                    $qb = $er->createQueryBuilder('a')
                        ->where('a.instituto = :instituto')
                        ->setParameter('instituto', $instituto);
                    
                    if ($isEdit) {
                        $qb->andWhere('a.id != :id')
                           ->setParameter('id', $this->alumno->getId());
                    }
                    
                    return $qb;
                },
                'multiple' => true,
                'expanded' => false,
                'required' => false,
                'label' => 'Hermanos',
                'attr' => ['class' => 'form-control predictivo']
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Alumno::class,
            'is_edit' => false,
            'instituto' => false,
        ]);
    }
}
