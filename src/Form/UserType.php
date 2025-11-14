<?php

namespace App\Form;

use App\Entity\User;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class UserType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $isEdit = $options['is_edit'];
        $allowAdminRole = $options['allow_admin_role'] ?? false;
        
        // Construir opciones de roles según permisos
        $roleChoices = [
            'Operador' => "ROLE_USER",
        ];
        
        // Solo SUPER_ADMIN puede asignar ROLE_ADMIN
        if ($allowAdminRole) {
            $roleChoices['Administrador'] = "ROLE_ADMIN";
        }
        
        $builder
            ->add('email', TextType::class, ['label' => 'Email', 'attr' => ['class' => 'form-control'], 'required' => !$isEdit])
            ->add('roles', ChoiceType::class, [
                'choices' => $roleChoices,
                'choice_attr' => function($choice, $key, $value) {
                    return ['class' => 'form-check-input'];
                },
                'multiple'=>true,
                'expanded'=>true,
                'required' => !$isEdit
            ])
            ->add('password', PasswordType::class, ['label' => 'Password', 'attr' => ['class' => 'form-control'], 'required' => !$isEdit, 'mapped' => false])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => User::class,
            'is_edit' => false, // Valor por defecto
            'allow_admin_role' => false, // Por defecto no permitir crear ADMIN
        ]);
    }
}
