<?php

namespace App\Controller;

use App\Entity\User;
use App\Form\InstitutoUserType;
use App\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Annotation\Route;
use App\Service\TokenService;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * @Route("/instituto/user")
 */
#[IsGranted('ROLE_ADMIN_INSTITUTO')]
class InstitutoUserController extends AbstractController
{
    private TokenService $tokenService;

    public function __construct(TokenService $tokenService)
    {
        $this->tokenService = $tokenService;
    }

    /**
     * @Route("/", name="instituto_user_index", methods={"GET"})
     */
    public function index(UserRepository $userRepository): Response
    {
        $usuarioActual = $this->getUser();
        if (!$usuarioActual || !$usuarioActual->getInstituto()) {
            $this->addFlash('danger', 'No tienes un instituto asignado.');
            return $this->redirectToRoute('app_login');
        }
        
        $instituto = $usuarioActual->getInstituto();
        
        // Obtener todos los usuarios del instituto excepto el usuario actual
        $users = $userRepository->findBy(
            ['instituto' => $instituto],
            ['email' => 'ASC']
        );
        
        // Filtrar el usuario actual
        $users = array_filter($users, function($user) use ($usuarioActual) {
            return $user->getId() !== $usuarioActual->getId();
        });

        return $this->render('instituto/user/index.html.twig', [
            'users' => $users,
        ]);
    }

    /**
     * @Route("/new", name="instituto_user_new", methods={"GET", "POST"})
     */
    public function new(Request $request, UserRepository $userRepository, UserPasswordHasherInterface $passwordHasher): Response
    {
        $usuarioActual = $this->getUser();
        if (!$usuarioActual || !$usuarioActual->getInstituto()) {
            $this->addFlash('danger', 'No tienes un instituto asignado.');
            return $this->redirectToRoute('app_login');
        }
        
        $instituto = $usuarioActual->getInstituto();
        $user = new User();
        $user->setInstituto($instituto);
        
        $form = $this->createForm(InstitutoUserType::class, $user, [
            'is_edit' => false
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // Verificar tokens antes de crear
            if (!$this->tokenService->hasEnoughTokens($instituto, 'usuario.create')) {
                $this->addFlash('danger', 'No tienes suficientes tokens para crear un usuario. Balance actual: ' . $this->tokenService->getBalance($instituto)->getBalance());
                return $this->renderForm('instituto/user/new.html.twig', [
                    'user' => $user,
                    'form' => $form,
                ]);
            }

            $email = $form->get('email')->getData();
            $password = $form->get('password')->getData();
            
            // Verificar si el email ya existe
            $existingUser = $userRepository->findOneBy(['email' => $email]);
            if ($existingUser) {
                $this->addFlash('danger', 'El correo electrónico "' . $email . '" ya está registrado en el sistema.');
                return $this->renderForm('instituto/user/new.html.twig', [
                    'user' => $user,
                    'form' => $form,
                ]);
            }

            $user->setEmail($email);
            $user->setPassword($passwordHasher->hashPassword($user, $password));
            $user->setRoles(['ROLE_ADMIN_INSTITUTO']); // Solo crear usuarios Administrador del Instituto
            $user->setInstituto($instituto);
            
            $userRepository->add($user, true);

            // Consumir tokens después de guardar exitosamente
            $this->tokenService->consumeTokens(
                $instituto,
                'usuario.create',
                $this->getUser(),
                'Crear usuario: ' . $user->getEmail(),
                'User',
                $user->getId()
            );

            $this->addFlash('success', 'Usuario creado exitosamente.');
            return $this->redirectToRoute('instituto_user_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->renderForm('instituto/user/new.html.twig', [
            'user' => $user,
            'form' => $form,
        ]);
    }

    /**
     * @Route("/{id}", name="instituto_user_show", methods={"GET"})
     */
    public function show(User $user): Response
    {
        $usuarioActual = $this->getUser();
        if (!$usuarioActual || !$usuarioActual->getInstituto()) {
            $this->addFlash('danger', 'No tienes un instituto asignado.');
            return $this->redirectToRoute('app_login');
        }
        
        // Verificar que el usuario pertenezca al mismo instituto
        if ($user->getInstituto() !== $usuarioActual->getInstituto()) {
            $this->addFlash('danger', 'No tienes permisos para ver este usuario.');
            return $this->redirectToRoute('instituto_user_index');
        }

        return $this->render('instituto/user/show.html.twig', [
            'user' => $user,
        ]);
    }

    /**
     * @Route("/{id}/edit", name="instituto_user_edit", methods={"GET", "POST"})
     */
    public function edit(Request $request, User $user, UserRepository $userRepository, UserPasswordHasherInterface $passwordHasher): Response
    {
        $usuarioActual = $this->getUser();
        if (!$usuarioActual || !$usuarioActual->getInstituto()) {
            $this->addFlash('danger', 'No tienes un instituto asignado.');
            return $this->redirectToRoute('app_login');
        }
        
        // Verificar que el usuario pertenezca al mismo instituto
        if ($user->getInstituto() !== $usuarioActual->getInstituto()) {
            $this->addFlash('danger', 'No tienes permisos para editar este usuario.');
            return $this->redirectToRoute('instituto_user_index');
        }
        
        // No permitir editar al mismo usuario actual
        if ($user->getId() === $usuarioActual->getId()) {
            $this->addFlash('warning', 'No puedes editar tu propio usuario desde aquí.');
            return $this->redirectToRoute('instituto_user_index');
        }
        
        $form = $this->createForm(InstitutoUserType::class, $user, [
            'is_edit' => true
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // Verificar tokens antes de editar
            if (!$this->tokenService->hasEnoughTokens($usuarioActual->getInstituto(), 'usuario.edit')) {
                $this->addFlash('danger', 'No tienes suficientes tokens para editar un usuario. Balance actual: ' . $this->tokenService->getBalance($usuarioActual->getInstituto())->getBalance());
                return $this->renderForm('instituto/user/edit.html.twig', [
                    'user' => $user,
                    'form' => $form,
                ]);
            }

            $newEmail = $form->get('email')->getData();
            $newPassword = $form->get('password')->getData();
            
            // Verificar si el email cambió y si ya existe
            if ($newEmail && $newEmail !== $user->getEmail()) {
                $existingUser = $userRepository->findOneBy(['email' => $newEmail]);
                if ($existingUser) {
                    $this->addFlash('danger', 'El correo electrónico "' . $newEmail . '" ya está registrado en el sistema.');
                    return $this->renderForm('instituto/user/edit.html.twig', [
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
            
            // Mantener el rol ROLE_ADMIN_INSTITUTO (no cambiar roles en edición)
            if (!in_array('ROLE_ADMIN_INSTITUTO', $user->getRoles())) {
                $user->setRoles(['ROLE_ADMIN_INSTITUTO']);
            }
            
            $userRepository->add($user, true);

            // Consumir tokens después de guardar exitosamente
            $this->tokenService->consumeTokens(
                $usuarioActual->getInstituto(),
                'usuario.edit',
                $this->getUser(),
                'Editar usuario: ' . $user->getEmail(),
                'User',
                $user->getId()
            );

            $this->addFlash('success', 'Usuario actualizado exitosamente.');
            return $this->redirectToRoute('instituto_user_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->renderForm('instituto/user/edit.html.twig', [
            'user' => $user,
            'form' => $form,
        ]);
    }

    /**
     * @Route("/{id}", name="instituto_user_delete", methods={"POST"})
     */
    public function delete(Request $request, User $user, UserRepository $userRepository): Response
    {
        $usuarioActual = $this->getUser();
        if (!$usuarioActual || !$usuarioActual->getInstituto()) {
            $this->addFlash('danger', 'No tienes un instituto asignado.');
            return $this->redirectToRoute('app_login');
        }
        
        // Verificar que el usuario pertenezca al mismo instituto
        if ($user->getInstituto() !== $usuarioActual->getInstituto()) {
            $this->addFlash('danger', 'No tienes permisos para eliminar este usuario.');
            return $this->redirectToRoute('instituto_user_index');
        }
        
        // No permitir eliminar al mismo usuario actual
        if ($user->getId() === $usuarioActual->getId()) {
            $this->addFlash('warning', 'No puedes eliminar tu propio usuario.');
            return $this->redirectToRoute('instituto_user_index');
        }

        // Verificar tokens antes de eliminar
        if (!$this->tokenService->hasEnoughTokens($usuarioActual->getInstituto(), 'usuario.delete')) {
            $this->addFlash('danger', 'No tienes suficientes tokens para eliminar un usuario. Balance actual: ' . $this->tokenService->getBalance($usuarioActual->getInstituto())->getBalance());
            return $this->redirectToRoute('instituto_user_index');
        }

        if ($this->isCsrfTokenValid('delete'.$user->getId(), $request->request->get('_token'))) {
            $userRepository->remove($user, true);
            
            // Consumir tokens después de eliminar exitosamente
            $this->tokenService->consumeTokens(
                $usuarioActual->getInstituto(),
                'usuario.delete',
                $this->getUser(),
                'Eliminar usuario: ' . $user->getEmail(),
                'User',
                $user->getId()
            );
            
            $this->addFlash('success', 'Usuario eliminado exitosamente.');
        }

        return $this->redirectToRoute('instituto_user_index', [], Response::HTTP_SEE_OTHER);
    }
}

