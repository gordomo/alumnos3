<?php
namespace App\Form;

use App\Entity\InstitutoPaginaPublica;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\{CheckboxType, ColorType, TextareaType};
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class InstitutoPaginaPublicaType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('presentacion', TextareaType::class, ['required' => false, 'attr' => ['maxlength' => 600, 'rows' => 4]])
            ->add('colorPrincipal', ColorType::class)
            ->add('colorAcento', ColorType::class)
            ->add('mostrarPrecios', CheckboxType::class, ['required' => false])
            ->add('publicada', CheckboxType::class, ['required' => false]);
    }
    public function configureOptions(OptionsResolver $resolver): void { $resolver->setDefaults(['data_class' => InstitutoPaginaPublica::class]); }
}
