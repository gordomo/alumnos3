<?php

namespace App\Controller;

use App\Entity\User;
use App\Form\UserType;
use App\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Annotation\Route;

/**
 * @Route("/admin/user")
 */
class UserController extends AbstractController
{
    /**
     * @Route("/", name="app_user_index", methods={"GET"})
     */
    public function index(UserRepository $userRepository): Response
    {
        $instituto = $this->getUser()->getInstituto();
        
        return $this->render('user/index.html.twig', [
            'users' => $userRepository->findBy(['instituto' => $instituto]),
        ]);
    }

    /**
     * @Route("/new", name="app_user_new", methods={"GET", "POST"})
     */
    public function new(Request $request, UserRepository $userRepository, UserPasswordHasherInterface $passwordEncoder): Response
    {
        $usuarioActual = $this->getUser();
        $instituto = $usuarioActual->getInstituto();
        $user = new User();
        $user->setInstituto($instituto);

        $isSuperAdmin = $usuarioActual && in_array('ROLE_SUPER_ADMIN', $usuarioActual->getRoles());
        
        $form = $this->createForm(UserType::class, $user, [
            'is_edit' => false,
            'allow_admin_role' => $isSuperAdmin
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $newEmail = $form->get('email')->getData();
            $newPassword = $form->get('password')->getData();
            $roles = $form->get('roles')->getData();

            // Validar que solo SUPER_ADMIN puede asignar ROLE_ADMIN
            if (in_array('ROLE_ADMIN', $roles) && !$isSuperAdmin) {
                $this->addFlash('danger', 'No tienes permisos para crear usuarios con rol ADMIN.');
                return $this->renderForm('user/new.html.twig', [
                    'user' => $user,
                    'form' => $form,
                ]);
            }

            $user->setPassword($passwordEncoder->hashPassword($user, $newPassword));
            $user->setEmail($newEmail);
            $user->setRoles($roles);
            $userRepository->add($user);
            return $this->redirectToRoute('app_user_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->renderForm('user/new.html.twig', [
            'user' => $user,
            'form' => $form,
        ]);
    }

    /**
     * @Route("/{id}", name="app_user_show", methods={"GET"})
     */
    public function show(User $user): Response
    {
        return $this->render('user/show.html.twig', [
            'user' => $user,
        ]);
    }

    /**
     * @Route("/{id}/edit", name="app_user_edit", methods={"GET", "POST"})
     */
    public function edit(Request $request, User $user, UserRepository $userRepository, UserPasswordHasherInterface $passwordEncoder): Response
    {
        $form = $this->createForm(UserType::class, $user, ['is_edit' => true]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $newEmail = $form->get('email')->getData();
            $newPassword = $form->get('password')->getData();
            
            if (!empty($newPassword)) {
                $user->setPassword($passwordEncoder->hashPassword($user, $newPassword));
            }
            if ($newEmail && $user->getEmail() !== $newEmail) {
                $user->setEmail($newEmail);
            }
            $userRepository->add($user);

            return $this->redirectToRoute('app_user_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->renderForm('user/edit.html.twig', [
            'user' => $user,
            'form' => $form,
        ]);
    }

    /**
     * @Route("/{id}", name="app_user_delete", methods={"POST"})
     */
    public function delete(Request $request, User $user, UserRepository $userRepository): Response
    {
        if ($this->isCsrfTokenValid('delete'.$user->getId(), $request->request->get('_token'))) {
            $userRepository->remove($user);
        }

        return $this->redirectToRoute('app_user_index', [], Response::HTTP_SEE_OTHER);
    }
}
