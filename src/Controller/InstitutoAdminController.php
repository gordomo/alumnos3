<?php

namespace App\Controller;

use App\Entity\Instituto;
use App\Entity\User;
use App\Form\InstitutoType;
use App\Repository\AlumnoRepository;
use App\Repository\InstitutoRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Annotation\Route;

/**
 * @Route("/admin/instituto")
 */
class InstitutoAdminController extends AbstractController
{
    /**
     * @Route("/", name="admin_instituto_index", methods={"GET"})
     */
    public function index(InstitutoRepository $institutoRepository): Response
    {
        $institutos = $institutoRepository->findAll();

        return $this->render('admin/instituto/index.html.twig', [
            'institutos' => $institutos,
        ]);
    }

    /**
     * @Route("/new", name="admin_instituto_new", methods={"GET", "POST"})
     */
    public function new(Request $request, EntityManagerInterface $em, UserPasswordHasherInterface $passwordEncoder ): Response {
            $instituto = new Instituto();
            $form = $this->createForm(InstitutoType::class, $instituto);
    
            $form->handleRequest($request);
            if ($form->isSubmitted() && $form->isValid()) {
                $email = $form->get('email')->getData();
                $dir = $form->get('dir')->getData();
    
                // Verificación de unicidad del email
                $institutoExistente = $em->getRepository(Instituto::class)->findOneBy(['email' => $email]);
                $usuarioExistente = $em->getRepository(User::class)->findOneBy(['email' => $email]);
    
                if ($institutoExistente || $usuarioExistente) {
                    $this->addFlash('danger', 'El correo electrónico ya está en uso.');
                    return $this->render('admin/instituto/new.html.twig', [
                        'form' => $form->createView(),
                    ]);
                }   
                $instituto->setEmail($email);
                $instituto->setDir($dir);
                // Creación del usuario asociado al instituto
                $pass = $form->get('password')->getData();
                if (!$pass) {
                    $this->addFlash('danger', 'el Password es obligatorio, lo usaremos para crear el primer usuario del instituto');
                    return $this->render('admin/instituto/new.html.twig', [
                        'form' => $form->createView(),
                    ]);
                }
                $user = new User();
                $user->setEmail($email);
                $user->setPassword($passwordEncoder->hashPassword($user, $pass));
                
                $user->setRoles(['ROLE_ADMIN']);
                $user->setInstituto($instituto);
    
                // Manejo del logo
                $file = $form->get('logo')->getData();
                if ($file) {
                    $fileName = md5(uniqid()).'.'.$file->guessExtension();
                    $file->move($this->getParameter('logos_directory'), $fileName);
                    $instituto->setLogo($fileName);
                }
                
                $em->persist($user);
                $em->persist($instituto);
                $em->flush();
    
                return $this->redirectToRoute('admin_instituto_index');
            }
    
            return $this->render('admin/instituto/new.html.twig', [
                'form' => $form->createView(),
            ]);
        }
    

    /**
     * @Route("/{id}/edit", name="admin_instituto_edit", methods={"GET","POST"})
     */
    public function edit(
        Request $request, 
        Instituto $instituto, 
        UserRepository $userRepository, 
        UserPasswordHasherInterface $passwordHasher,
        EntityManagerInterface $entityManager
    ): Response {
        $form = $this->createForm(InstitutoType::class, $instituto);
        $form->handleRequest($request);
        
        if ($form->isSubmitted() && $form->isValid()) {
            $newEmail = $form->get('email')->getData();
            $newPassword = $form->get('password')->getData();

            // Encuentra el usuario actual asociado al instituto (si existe)
            $user = $userRepository->findOneBy([
                'instituto' => $instituto
            ], ['id' => 'ASC']); // Usuario con el ID más bajo asociado al instituto

            if ($user) {
                // Verifica si el email actual ha cambiado
                if ($newEmail && $user->getEmail() !== $newEmail) {
                    // Verifica la existencia de ese email en otro usuario
                    $existingUser = $userRepository->findOneBy(['email' => $newEmail]);
                    if ($existingUser) {
                        $this->addFlash('danger', 'El correo electrónico ya está en uso.');
                        return $this->redirectToRoute('admin_instituto_edit', ['id' => $instituto->getId()]);
                    }

                    $user->setEmail($newEmail);
                }

                // Cambia la contraseña si se ha proporcionado una nueva
                if ($newPassword) {
                    $hashedPassword = $passwordHasher->hashPassword($user, $newPassword);
                    $user->setPassword($hashedPassword);
                }
            } else {
                // Crea un nuevo usuario si ninguno está asociado al instituto
                if ($newEmail && $newPassword) {
                    // Verifica si el nuevo correo electrónico ya está en uso.
                    $existingUser = $userRepository->findOneBy(['email' => $newEmail]);
                    if ($existingUser) {
                        $this->addFlash('danger', 'El correo electrónico ya está en uso.');
                        return $this->redirectToRoute('admin_instituto_edit', ['id' => $instituto->getId()]);
                    }

                    $user = new User();
                    $user->setEmail($newEmail);
                    $user->setRoles(['ROLE_ADMIN']);
                    $user->setInstituto($instituto);

                    // Establece y hashea la nueva contraseña
                    $hashedPassword = $passwordHasher->hashPassword($user, $newPassword);
                    $user->setPassword($hashedPassword);

                    $entityManager->persist($user);
                } else {
                    $this->addFlash('danger', 'Debe proporcionar un correo electrónico y una contraseña para crear un usuario.');
                    return $this->redirectToRoute('admin_instituto_edit', ['id' => $instituto->getId()]);
                }
            }

           // Manejo de la carga de imágenes
            $logoFile = $form->get('logo')->getData();
            if ($logoFile) {
                // Obtener el nombre del archivo anterior
                $oldFilename = $instituto->getLogo();

                // Generar un nuevo nombre de archivo único para el logo
                $newFilename = uniqid() . '.' . $logoFile->guessExtension();

                try {
                    // Mover el nuevo archivo a la ubicación de destino
                    $logoFile->move(
                        $this->getParameter('logos_directory'),
                        $newFilename
                    );

                    // Actualizar la entidad Instituto con el nuevo nombre de archivo
                    $instituto->setLogo($newFilename);

                    // Eliminar el archivo de logo anterior si existía
                    if ($oldFilename) {
                        $oldFilepath = $this->getParameter('logos_directory') . '/' . $oldFilename;
                        if (file_exists($oldFilepath)) {
                            unlink($oldFilepath);
                        }
                    }
                } catch (FileException $e) {
                    $this->addFlash('danger', 'Error subiendo el archivo.');
                    return $this->redirectToRoute('admin_instituto_edit', ['id' => $instituto->getId()]);
                }
            }
            $entityManager->flush();
            $this->addFlash('success', 'Instituto actualizado correctamente.');

            return $this->redirectToRoute('admin_instituto_index');
        }

        return $this->render('admin/instituto/edit.html.twig', [
            'form' => $form->createView(),
            'instituto' => $instituto
        ]);
    }

    /**
     * @Route("/{id}", name="admin_instituto_show", methods={"GET"})
     */
    public function show(Instituto $instituto, AlumnoRepository $alumnoRepository): Response
    {
        $totalAlumnos = $alumnoRepository->countByInstituto($instituto);
        $alumnosActivos = $alumnoRepository->countByInstitutoAndStatus($instituto, true);
        $alumnosInactivos = $totalAlumnos - $alumnosActivos;

        return $this->render('admin/instituto/show.html.twig', [
            'instituto' => $instituto,
            'total_alumnos' => $totalAlumnos,
            'alumnos_activos' => $alumnosActivos,
            'alumnos_inactivos' => $alumnosInactivos,
        ]);
    }

    /**
     * @Route("/{id}", name="admin_instituto_delete", methods={"POST"})
     */
    public function delete(Request $request, Instituto $instituto, EntityManagerInterface $em): Response
    {
        if ($this->isCsrfTokenValid('delete'.$instituto->getId(), $request->request->get('_token'))) {
            $em->remove($instituto);
            $em->flush();
        }

        return $this->redirectToRoute('admin_instituto_index');
    }
}