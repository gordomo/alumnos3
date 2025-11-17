<?php

namespace App\Controller;

use App\Entity\User;
use App\Form\AdminUserType;
use App\Repository\UserRepository;
use App\Repository\InstitutoAdminRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Annotation\Route;

/**
 * @Route("/admin/admin-user")
 */
class AdminUserController extends AbstractController
{
    /**
     * @Route("/", name="admin_user_index", methods={"GET"})
     */
    public function index(UserRepository $userRepository): Response
    {
        $usuarioActual = $this->getUser();
        
        // Solo SUPER_ADMIN puede ver usuarios ADMIN
        if (!$usuarioActual || !in_array('ROLE_SUPER_ADMIN', $usuarioActual->getRoles())) {
            $this->addFlash('danger', 'No tienes permisos para acceder a esta sección.');
            return $this->redirectToRoute('admin_instituto_index');
        }
        
        $adminUsers = $userRepository->findAdminUsers();

        return $this->render('admin/user/index.html.twig', [
            'users' => $adminUsers,
        ]);
    }

    /**
     * @Route("/new", name="admin_user_new", methods={"GET", "POST"})
     */
    public function new(Request $request, UserRepository $userRepository, UserPasswordHasherInterface $passwordHasher): Response
    {
        $usuarioActual = $this->getUser();
        
        // Solo SUPER_ADMIN puede crear usuarios ADMIN
        if (!$usuarioActual || !in_array('ROLE_SUPER_ADMIN', $usuarioActual->getRoles())) {
            $this->addFlash('danger', 'No tienes permisos para crear usuarios administradores.');
            return $this->redirectToRoute('admin_user_index');
        }
        
        $user = new User();
        $user->setRoles(['ROLE_ADMIN']);
        
        $form = $this->createForm(AdminUserType::class, $user, ['is_edit' => false]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $email = $form->get('email')->getData();
            $password = $form->get('password')->getData();
            
            // Verificar si el email ya existe
            $existingUser = $userRepository->findOneBy(['email' => $email]);
            if ($existingUser) {
                $this->addFlash('danger', 'El correo electrónico "' . $email . '" ya está registrado en el sistema.');
                return $this->renderForm('admin/user/new.html.twig', [
                    'user' => $user,
                    'form' => $form,
                ]);
            }

            $user->setEmail($email);
            $user->setPassword($passwordHasher->hashPassword($user, $password));
            $user->setRoles(['ROLE_ADMIN']);
            
            $userRepository->add($user, true);

            $this->addFlash('success', 'Usuario administrador creado exitosamente.');
            return $this->redirectToRoute('admin_user_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->renderForm('admin/user/new.html.twig', [
            'user' => $user,
            'form' => $form,
        ]);
    }

    /**
     * @Route("/{id}", name="admin_user_show", methods={"GET"})
     */
    public function show(User $user, UserRepository $userRepository, InstitutoAdminRepository $institutoAdminRepository): Response
    {
        $usuarioActual = $this->getUser();
        
        // Solo SUPER_ADMIN puede ver usuarios ADMIN
        if (!$usuarioActual || !in_array('ROLE_SUPER_ADMIN', $usuarioActual->getRoles())) {
            $this->addFlash('danger', 'No tienes permisos para ver este usuario.');
            return $this->redirectToRoute('admin_user_index');
        }
        
        // Verificar que el usuario sea ADMIN
        if (!in_array('ROLE_ADMIN', $user->getRoles()) && !in_array('ROLE_SUPER_ADMIN', $user->getRoles())) {
            $this->addFlash('danger', 'Este usuario no es un administrador.');
            return $this->redirectToRoute('admin_user_index');
        }

        // Obtener los institutos creados por este usuario
        $institutosCreados = $institutoAdminRepository->findInstitutosCreadosPorUsuario($user);

        return $this->render('admin/user/show.html.twig', [
            'user' => $user,
            'institutosCreados' => $institutosCreados,
        ]);
    }

    /**
     * @Route("/{id}/edit", name="admin_user_edit", methods={"GET", "POST"})
     */
    public function edit(Request $request, User $user, UserRepository $userRepository, UserPasswordHasherInterface $passwordHasher): Response
    {
        $usuarioActual = $this->getUser();
        
        // Solo SUPER_ADMIN puede editar usuarios ADMIN
        if (!$usuarioActual || !in_array('ROLE_SUPER_ADMIN', $usuarioActual->getRoles())) {
            $this->addFlash('danger', 'No tienes permisos para editar usuarios administradores.');
            return $this->redirectToRoute('admin_user_index');
        }
        
        // Verificar que el usuario sea ADMIN
        if (!in_array('ROLE_ADMIN', $user->getRoles()) && !in_array('ROLE_SUPER_ADMIN', $user->getRoles())) {
            $this->addFlash('danger', 'Este usuario no es un administrador.');
            return $this->redirectToRoute('admin_user_index');
        }
        
        // No permitir editar al mismo usuario SUPER_ADMIN actual
        if ($user->getId() === $usuarioActual->getId()) {
            $this->addFlash('warning', 'No puedes editar tu propio usuario desde aquí.');
            return $this->redirectToRoute('admin_user_index');
        }
        
        $emailOriginal = $user->getEmail();
        
        $form = $this->createForm(AdminUserType::class, $user, ['is_edit' => true]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $newEmail = $form->get('email')->getData();
            $newPassword = $form->get('password')->getData();
            
            // Verificar si el email cambió y si ya existe
            if ($newEmail && $newEmail !== $emailOriginal) {
                $existingUser = $userRepository->findOneBy(['email' => $newEmail]);
                if ($existingUser) {
                    $this->addFlash('danger', 'El correo electrónico "' . $newEmail . '" ya está registrado en el sistema.');
                    return $this->renderForm('admin/user/edit.html.twig', [
                        'user' => $user,
                        'form' => $form,
                    ]);
                }
                $user->setEmail($newEmail);
            }
            
            // Actualizar contraseña solo si se proporcionó una nueva
            if ($newPassword) {
                $user->setPassword($passwordHasher->hashPassword($user, $newPassword));
            }
            
            $userRepository->add($user, true);

            $this->addFlash('success', 'Usuario administrador actualizado exitosamente.');
            return $this->redirectToRoute('admin_user_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->renderForm('admin/user/edit.html.twig', [
            'user' => $user,
            'form' => $form,
        ]);
    }

    /**
     * @Route("/{id}", name="admin_user_delete", methods={"POST"})
     */
    public function delete(Request $request, User $user, UserRepository $userRepository): Response
    {
        $usuarioActual = $this->getUser();
        
        // Solo SUPER_ADMIN puede eliminar usuarios ADMIN
        if (!$usuarioActual || !in_array('ROLE_SUPER_ADMIN', $usuarioActual->getRoles())) {
            $this->addFlash('danger', 'No tienes permisos para eliminar usuarios administradores.');
            return $this->redirectToRoute('admin_user_index');
        }
        
        // Verificar que el usuario sea ADMIN
        if (!in_array('ROLE_ADMIN', $user->getRoles()) && !in_array('ROLE_SUPER_ADMIN', $user->getRoles())) {
            $this->addFlash('danger', 'Este usuario no es un administrador.');
            return $this->redirectToRoute('admin_user_index');
        }
        
        // No permitir eliminar al mismo usuario SUPER_ADMIN actual
        if ($user->getId() === $usuarioActual->getId()) {
            $this->addFlash('warning', 'No puedes eliminar tu propio usuario.');
            return $this->redirectToRoute('admin_user_index');
        }
        
        if ($this->isCsrfTokenValid('delete'.$user->getId(), $request->request->get('_token'))) {
            $userRepository->remove($user, true);
            $this->addFlash('success', 'Usuario administrador eliminado exitosamente.');
        }

        return $this->redirectToRoute('admin_user_index', [], Response::HTTP_SEE_OTHER);
    }
}

