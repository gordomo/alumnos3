<?php

namespace App\Controller;

use App\Entity\Instituto;
use App\Entity\InstitutoAdmin;
use App\Entity\InstitutoConfiguracion;
use App\Entity\MetodoPago;
use App\Entity\User;
use App\Form\InstitutoType;
use App\Repository\AlumnoRepository;
use App\Repository\InstitutoAdminRepository;
use App\Repository\InstitutoRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Knp\Component\Pager\PaginatorInterface;
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

    private function generateRandomPassword($length = 12) {
        $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*()_+';
        $password = '';
        for ($i = 0; $i < $length; $i++) {
            $password .= $chars[random_int(0, strlen($chars) - 1)];
        }
        return $password;
    }

    /**
     * @Route("/", name="admin_instituto_index", methods={"GET"})
     */
    public function index(
        Request $request,
        InstitutoRepository $institutoRepository,
        UserRepository $userRepository,
        PaginatorInterface $paginator
    ): Response {
        $usuario = $this->getUser();
        
        // Obtener parámetros de filtrado
        $nombre = $request->query->get('nombre', '');
        $usuarioCreadorId = $request->query->get('usuarioCreador', '');
        $page = $request->query->getInt('page', 1);
        
        // Obtener el usuario creador si se especificó
        $usuarioCreador = null;
        if ($usuarioCreadorId) {
            $usuarioCreador = $userRepository->find($usuarioCreadorId);
        }
        
        // Crear query con filtros
        $queryBuilder = $institutoRepository->createQueryBuilderWithFilters(
            $usuario,
            $nombre,
            $usuarioCreador
        );
        
        // Paginar resultados
        $institutos = $paginator->paginate(
            $queryBuilder->getQuery(),
            $page,
            10 // 10 items por página
        );
        
        // Obtener lista de usuarios admin para el desplegable (solo si es SUPER_ADMIN)
        $usuariosAdmin = [];
        if ($usuario && in_array('ROLE_SUPER_ADMIN', $usuario->getRoles())) {
            $usuariosAdmin = $userRepository->findAdminUsers();
        }

        return $this->render('admin/instituto/index.html.twig', [
            'institutos' => $institutos,
            'nombre' => $nombre,
            'usuarioCreadorId' => $usuarioCreadorId,
            'usuariosAdmin' => $usuariosAdmin,
        ]);
    }

    /**
     * @Route("/new", name="admin_instituto_new", methods={"GET", "POST"})
     */
    public function new(Request $request, EntityManagerInterface $em, UserPasswordHasherInterface $passwordEncoder): Response
    {
        $usuarioActual = $this->getUser();
        
        // Solo SUPER_ADMIN y ADMIN pueden crear institutos
        if (!$usuarioActual || (!in_array('ROLE_SUPER_ADMIN', $usuarioActual->getRoles()) && !in_array('ROLE_ADMIN', $usuarioActual->getRoles()))) {
            $this->addFlash('danger', 'No tienes permisos para crear institutos.');
            return $this->redirectToRoute('admin_instituto_index');
        }
        
        $instituto = new Instituto();
        $form = $this->createForm(InstitutoType::class, $instituto);

        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $email = $form->get('email')->getData();
            $dir = $form->get('dir')->getData();
            $password = $form->get('password')->getData();

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

            // Crear usuario admin del instituto
            $user = new User();
            $user->setEmail($email);
            $user->setPassword($passwordEncoder->hashPassword($user, $password));
            $user->setRoles(['ROLE_ADMIN_INSTITUTO']);
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

            // Crear configuración del instituto (timezone del navegador del admin que lo crea)
            $configuracion = new InstitutoConfiguracion();
            $configuracion->setInstituto($instituto);
            $timezone = $request->request->get('timezone');
            $configuracion->setTimezone($timezone !== '' && $timezone !== null ? $timezone : null);
            $configuracion->setDateFormat('d/m/Y'); // formato por defecto al crear instituto
            $em->persist($configuracion);

            // Métodos de pago base del instituto. "Efectivo" debe existir siempre.
            $metodosBase = ['Efectivo', 'Transferencia', 'Tarjeta de Debito', 'Tarjeta de Credito'];
            foreach ($metodosBase as $index => $nombreMetodo) {
                $metodoPago = new MetodoPago();
                $metodoPago->setInstituto($instituto);
                $metodoPago->setNombre($nombreMetodo);
                $metodoPago->setActivo(true);
                $metodoPago->setOrden($index + 1);
                $em->persist($metodoPago);
            }

            // Crear registro en InstitutoAdmin para asignar el creador
            $institutoAdmin = new InstitutoAdmin();
            $institutoAdmin->setInstituto($instituto);
            $institutoAdmin->setUser($usuarioActual);
            $institutoAdmin->setActivo(true);
            $em->persist($institutoAdmin);

            $em->flush();

            $this->addFlash('success', 'Instituto creado correctamente.');
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
        InstitutoAdminRepository $institutoAdminRepository,
        UserPasswordHasherInterface $passwordHasher,
        EntityManagerInterface $entityManager
    ): Response {
        $usuarioActual = $this->getUser();
        
        // Verificar permisos: SUPER_ADMIN puede editar todos, ADMIN solo los suyos
        if (!$usuarioActual || !$institutoAdminRepository->usuarioTieneAcceso($usuarioActual, $instituto)) {
            $this->addFlash('danger', 'No tienes permisos para editar este instituto.');
            return $this->redirectToRoute('admin_instituto_index');
        }
        $form = $this->createForm(InstitutoType::class, $instituto, ['is_edit' => true]);
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
    public function show(
        Instituto $instituto, 
        AlumnoRepository $alumnoRepository, 
        UserRepository $userRepository,
        InstitutoAdminRepository $institutoAdminRepository
    ): Response {
        $usuarioActual = $this->getUser();
        
        // Verificar permisos: SUPER_ADMIN puede ver todos, ADMIN solo los suyos
        if (!$usuarioActual || !$institutoAdminRepository->usuarioTieneAcceso($usuarioActual, $instituto)) {
            $this->addFlash('danger', 'No tienes permisos para ver este instituto.');
            return $this->redirectToRoute('admin_instituto_index');
        }
        $totalAlumnos = $alumnoRepository->countByInstituto($instituto);
        $alumnosActivos = $alumnoRepository->countByInstitutoAndStatus($instituto, true);
        $alumnosInactivos = $totalAlumnos - $alumnosActivos;

        // Estadísticas de usuarios
        $totalUsuarios = $instituto->getUsuarios()->count();
        $usuariosAdmin = 0;
        $usuariosProfesor = 0;
        $otrosUsuarios = 0;

        foreach ($instituto->getUsuarios() as $usuario) {
            if (in_array('ROLE_ADMIN_INSTITUTO', $usuario->getRoles())) {
                $usuariosAdmin++;
            } elseif (in_array('ROLE_PROFESOR', $usuario->getRoles())) {
                $usuariosProfesor++;
            } else {
                $otrosUsuarios++;
            }
        }

        return $this->render('admin/instituto/show.html.twig', [
            'instituto' => $instituto,
            'total_alumnos' => $totalAlumnos,
            'alumnos_activos' => $alumnosActivos,
            'alumnos_inactivos' => $alumnosInactivos,
            'total_usuarios' => $totalUsuarios,
            'usuarios_admin' => $usuariosAdmin,
            'usuarios_profesor' => $usuariosProfesor,
            'otros_usuarios' => $otrosUsuarios,
        ]);
    }


    /**
     * @Route("/{id}", name="admin_instituto_delete", methods={"POST"})
     */
    public function delete(
        Request $request, 
        Instituto $instituto, 
        InstitutoAdminRepository $institutoAdminRepository,
        EntityManagerInterface $em
    ): Response {
        $usuarioActual = $this->getUser();
        
        // Verificar permisos: SUPER_ADMIN puede eliminar todos, ADMIN solo los suyos
        if (!$usuarioActual || !$institutoAdminRepository->usuarioTieneAcceso($usuarioActual, $instituto)) {
            $this->addFlash('danger', 'No tienes permisos para eliminar este instituto.');
            return $this->redirectToRoute('admin_instituto_index');
        }
        
        if ($this->isCsrfTokenValid('delete'.$instituto->getId(), $request->request->get('_token'))) {
            $em->remove($instituto);
            $em->flush();
        }

        return $this->redirectToRoute('admin_instituto_index');
    }
}