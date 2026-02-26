<?php

namespace App\Controller;

use App\Entity\Instituto;
use App\Entity\Vencimiento;
use App\Entity\DescuentoPromocional;
use App\Repository\VencimientoRepository;
use App\Repository\InstitutoConfiguracionRepository;
use App\Repository\DescuentoPromocionalRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\String\Slugger\SluggerInterface;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use App\Service\BillingService;
use App\Service\TokenService;

/**
 * @Route("/instituto/config")
 */
class InstitutoConfigController extends AbstractController
{
    private BillingService $billingService;
    private TokenService $tokenService;

    public function __construct(BillingService $billingService, TokenService $tokenService)
    {
        $this->billingService = $billingService;
        $this->tokenService = $tokenService;
    }

    /**
     * @Route("/", name="instituto_config_index", methods={"GET"})
     */
    public function index(VencimientoRepository $vencimientoRepository, InstitutoConfiguracionRepository $configuracionRepository, DescuentoPromocionalRepository $descuentoPromocionalRepository): Response
    {
        $instituto = $this->getUser()->getInstituto();
        $vencimientos = $vencimientoRepository->findByInstitutoOrdered($instituto);
        $configuracion = $configuracionRepository->findOrCreateByInstituto($instituto);
        $descuentosPromocionales = $descuentoPromocionalRepository->findByConfiguracion($configuracion);
        usort($descuentosPromocionales, fn($a, $b) => (float) $a->getPorcentaje() <=> (float) $b->getPorcentaje());

        return $this->render('instituto_config/index.html.twig', [
            'instituto' => $instituto,
            'vencimientos' => $vencimientos,
            'configuracion' => $configuracion,
            'descuentosPromocionales' => $descuentosPromocionales,
        ]);
    }

    /**
     * @Route("/edit", name="instituto_config_edit", methods={"GET", "POST"})
     */
    public function edit(
        Request $request, 
        EntityManagerInterface $entityManager, 
        SluggerInterface $slugger, 
        ValidatorInterface $validator, 
        InstitutoConfiguracionRepository $configuracionRepository,
        VencimientoRepository $vencimientoRepository,
        DescuentoPromocionalRepository $descuentoPromocionalRepository
    ): Response {
        $instituto = $this->getUser()->getInstituto();
        $configuracion = $configuracionRepository->findOrCreateByInstituto($instituto);
        $vencimientos = $vencimientoRepository->findByInstitutoOrdered($instituto);
        $descuentosPromocionales = $descuentoPromocionalRepository->findByConfiguracion($configuracion);
        usort($descuentosPromocionales, fn($a, $b) => (float) $a->getPorcentaje() <=> (float) $b->getPorcentaje());

        if ($request->isMethod('POST')) {
            $section = $request->request->get('section', 'general');
            
            if ($section === 'general') {
            $instituto->setNombre($request->request->get('nombre'));
            $instituto->setEmail($request->request->get('email'));
            
            // Validación del teléfono
            $tel = $request->request->get('tel');
            if (strlen($tel) > 25) {
                    $this->addFlash('danger', 'El número de teléfono no puede tener más de 25 caracteres.');
                    return $this->redirectToRoute('instituto_config_index', ['tab' => 'general']);
            }
            $instituto->setTel($tel);
            
            $instituto->setDir($request->request->get('dir'));

            // Zona horaria del instituto (fechas, asistencias, "hoy")
            $timezone = $request->request->get('timezone');
            $configuracion->setTimezone($timezone !== '' ? $timezone : null);

            // Formato de fecha para mostrar en la app
            $dateFormat = $request->request->get('date_format');
            $configuracion->setDateFormat($dateFormat !== '' ? $dateFormat : null);

            // Manejo del logo
            if ($request->files->has('logo')) {
                $logoFile = $request->files->get('logo');
                if ($logoFile) {
                    $originalFilename = pathinfo($logoFile->getClientOriginalName(), PATHINFO_FILENAME);
                    $safeFilename = $slugger->slug($originalFilename);
                    $newFilename = $safeFilename.'-'.uniqid().'.'.$logoFile->guessExtension();
                    
                    try {
                        $logoFile->move(
                            $this->getParameter('logos_directory'),
                            $newFilename
                        );
                    } catch (FileException $e) {
                        $this->addFlash('danger', 'No se pudo subir el logo.');
                            return $this->redirectToRoute('instituto_config_index', ['tab' => 'general']);
                    }
                    
                    // Eliminar el logo anterior si existe
                    if ($instituto->getLogo()) {
                        $oldLogoPath = $this->getParameter('logos_directory').'/'.$instituto->getLogo();
                        if (file_exists($oldLogoPath)) {
                            unlink($oldLogoPath);
                        }
                    }
                    
                    $instituto->setLogo($newFilename);
                }
            }

            try {
                $errors = $validator->validate($instituto); 
                
                    if (count($errors) === 0) {
                    $entityManager->flush();
                        $this->addFlash('success', 'La información general se ha actualizado correctamente.');
                        return $this->redirectToRoute('instituto_config_index', ['tab' => 'general']);
                } else {
                    foreach ($errors as $error) {
                        $this->addFlash('danger', $error->getMessage());
                    }
                    return $this->redirectToRoute('instituto_config_index', ['tab' => 'general']);
                    }
                } catch (\Exception $e) {
                    $this->addFlash('danger', 'Ocurrió un error al guardar los cambios: ' . $e->getMessage());
                    return $this->redirectToRoute('instituto_config_index', ['tab' => 'general']);
                }
                
            } elseif ($section === 'descuentos') {
                // Configuración de descuentos
                $descuentoEfectivo = $request->request->get('descuentoEfectivo');
                $descuentoHermanos = $request->request->get('descuentoHermanos');
                // Checkbox "Habilitar descuentos para alumnos con deudas": marcado = habilitar (guardamos false en deshabilitar)
                $habilitarDescuentosEnDeuda = $request->request->has('deshabilitarDescuentosEnDeuda');
                $ordenCalculoInteresesDescuentos = $request->request->get('ordenCalculoInteresesDescuentos', 'interes_primero');
                
                $configuracion->setDescuentoEfectivo($descuentoEfectivo !== '' ? (float)$descuentoEfectivo : null);
                $configuracion->setDescuentoHermanos($descuentoHermanos !== '' ? (float)$descuentoHermanos : null);
                $configuracion->setDeshabilitarDescuentosEnDeuda(!$habilitarDescuentosEnDeuda);
                $configuracion->setOrdenCalculoInteresesDescuentos($ordenCalculoInteresesDescuentos);

                try {
                    $errorsConfig = $validator->validate($configuracion);
                    
                    if (count($errorsConfig) === 0) {
                        $entityManager->persist($configuracion);
                        $entityManager->flush();
                        $this->addFlash('success', 'La configuración de descuentos se ha actualizado correctamente.');
                        return $this->redirectToRoute('instituto_config_index', ['tab' => 'descuentos']);
                    } else {
                    foreach ($errorsConfig as $error) {
                        $this->addFlash('danger', $error->getMessage());
                    }
                    return $this->redirectToRoute('instituto_config_index', ['tab' => 'descuentos']);
                }
            } catch (\Exception $e) {
                $this->addFlash('danger', 'Ocurrió un error al guardar los cambios: ' . $e->getMessage());
                return $this->redirectToRoute('instituto_config_index', ['tab' => 'descuentos']);
                }
                
            } elseif ($section === 'notificaciones') {
                // Configuración de notificaciones
                $configuracion->setEnviarFacturasRecibos($request->request->has('enviarFacturasRecibos'));
                $configuracion->setEnviarRecordatoriosDeudas($request->request->has('enviarRecordatoriosDeudas'));
                $configuracion->setEnviarRecordatorioEnDiaVencimiento($request->request->has('enviarRecordatorioEnDiaVencimiento'));
                $configuracion->setTextoPersonalizadoEmail($request->request->get('textoPersonalizadoEmail'));

                try {
                    $errorsConfig = $validator->validate($configuracion);
                    
                    if (count($errorsConfig) === 0) {
                        $entityManager->persist($configuracion);
                        $entityManager->flush();
                        $this->addFlash('success', 'La configuración de notificaciones se ha actualizado correctamente.');
                        return $this->redirectToRoute('instituto_config_index', ['tab' => 'notificaciones']);
                    } else {
                        foreach ($errorsConfig as $error) {
                            $this->addFlash('danger', $error->getMessage());
                        }
                        return $this->redirectToRoute('instituto_config_index', ['tab' => 'notificaciones']);
                    }
                } catch (\Exception $e) {
                    $this->addFlash('danger', 'Ocurrió un error al guardar los cambios: ' . $e->getMessage());
                    return $this->redirectToRoute('instituto_config_index', ['tab' => 'notificaciones']);
                }
            }
        }

        return $this->render('instituto_config/edit.html.twig', [
            'instituto' => $instituto,
            'configuracion' => $configuracion,
            'vencimientos' => $vencimientos,
            'descuentosPromocionales' => $descuentosPromocionales
        ]);
    }

    /**
     * @Route("/vencimiento/new", name="instituto_config_vencimiento_new", methods={"GET", "POST"})
     */
    public function newVencimiento(Request $request, EntityManagerInterface $entityManager, ValidatorInterface $validator): Response
    {
        $vencimiento = new Vencimiento();
        $instituto = $this->getUser()->getInstituto();
        $vencimiento->setInstituto($instituto);

        if ($request->isMethod('POST')) {
            $diaVencimiento = $request->request->get('diaVencimiento');
            $porcentajeInteres = $request->request->get('porcentajeInteres');
            
            // Obtener el último orden
            $ultimoOrden = $entityManager->getRepository(Vencimiento::class)
                ->createQueryBuilder('v')
                ->select('MAX(v.orden)')
                ->where('v.instituto = :instituto')
                ->setParameter('instituto', $instituto)
                ->getQuery()
                ->getSingleScalarResult();

            $vencimiento->setDiaVencimiento((int)$diaVencimiento);
            $vencimiento->setPorcentajeInteres((float)$porcentajeInteres);
            $vencimiento->setOrden($ultimoOrden ? $ultimoOrden + 1 : 1);

            $errors = $validator->validate($vencimiento);
            if (count($errors) === 0) {
                $entityManager->persist($vencimiento);
                $entityManager->flush();
                $this->addFlash('success', 'Vencimiento creado correctamente.');
                return $this->redirectToRoute('instituto_config_index', ['tab' => 'pagos']);
            } else {
                foreach ($errors as $error) {
                    $this->addFlash('danger', $error->getMessage());
                }
            }
        }

        return $this->render('instituto_config/vencimiento_new.html.twig', [
            'vencimiento' => $vencimiento,
            'instituto' => $instituto
        ]);
    }

    /**
     * @Route("/vencimiento/{id}/edit", name="instituto_config_vencimiento_edit", methods={"GET", "POST"})
     */
    public function editVencimiento(Request $request, Vencimiento $vencimiento, EntityManagerInterface $entityManager, ValidatorInterface $validator): Response
    {
        if ($request->isMethod('POST')) {
            $diaVencimiento = $request->request->get('diaVencimiento');
            $porcentajeInteres = $request->request->get('porcentajeInteres');

            $vencimiento->setDiaVencimiento((int)$diaVencimiento);
            $vencimiento->setPorcentajeInteres((float)$porcentajeInteres);

            
            $errors = $validator->validate($vencimiento);
            
            if (count($errors) === 0) {
                $entityManager->persist($vencimiento);
                $entityManager->flush();
                $this->addFlash('success', 'Vencimiento actualizado correctamente.');
                return $this->redirectToRoute('instituto_config_index', ['tab' => 'pagos']);
            } else {
                foreach ($errors as $error) {
                    $this->addFlash('danger', $error->getMessage());
                }
            }
        }

        return $this->render('instituto_config/vencimiento_edit.html.twig', [
            'vencimiento' => $vencimiento,
            'instituto' => $vencimiento->getInstituto()
        ]);
    }

    /**
     * @Route("/vencimiento/{id}", name="instituto_config_vencimiento_delete", methods={"POST"})
     */
    public function deleteVencimiento(Request $request, Vencimiento $vencimiento, EntityManagerInterface $entityManager): Response
    {
        if ($this->isCsrfTokenValid('delete'.$vencimiento->getId(), $request->request->get('_token'))) {
            $entityManager->remove($vencimiento);
            $entityManager->flush();
            $this->addFlash('success', 'Vencimiento eliminado correctamente.');
        }

        return $this->redirectToRoute('instituto_config_index', ['tab' => 'pagos']);
    }

    /**
     * @Route("/descuento-promocional/new", name="instituto_config_descuento_promocional_new", methods={"GET", "POST"})
     */
    public function newDescuentoPromocional(Request $request, EntityManagerInterface $entityManager, ValidatorInterface $validator, InstitutoConfiguracionRepository $configuracionRepository): Response
    {
        $instituto = $this->getUser()->getInstituto();
        $configuracion = $configuracionRepository->findOrCreateByInstituto($instituto);
        $descuentoPromocional = new DescuentoPromocional();
        $descuentoPromocional->setConfiguracion($configuracion);

        if ($request->isMethod('POST')) {

            $nombre = $request->request->get('nombre');
            $porcentaje = $request->request->get('porcentaje');
            $activo = $request->request->has('activo');

            $descuentoPromocional->setNombre($nombre);
            $descuentoPromocional->setPorcentaje((float)$porcentaje);
            $descuentoPromocional->setActivo($activo);

            $errors = $validator->validate($descuentoPromocional);
            if (count($errors) === 0) {
                $entityManager->persist($descuentoPromocional);
                $entityManager->flush();

                $this->addFlash('success', 'Descuento promocional creado correctamente.');
                return $this->redirectToRoute('instituto_config_index', ['tab' => 'descuentos']);
            } else {
                foreach ($errors as $error) {
                    $this->addFlash('danger', $error->getMessage());
                }
            }
        }

        return $this->render('instituto_config/descuento_promocional_new.html.twig', [
            'descuentoPromocional' => $descuentoPromocional,
            'configuracion' => $configuracion
        ]);
    }

    /**
     * @Route("/descuento-promocional/{id}/edit", name="instituto_config_descuento_promocional_edit", methods={"GET", "POST"})
     */
    public function editDescuentoPromocional(Request $request, DescuentoPromocional $descuentoPromocional, EntityManagerInterface $entityManager, ValidatorInterface $validator): Response
    {
        $instituto = $this->getUser()->getInstituto();
        
        // Verificar que el descuento pertenece al instituto del usuario
        if ($descuentoPromocional->getConfiguracion()->getInstituto() !== $instituto) {
            $this->addFlash('danger', 'No tiene permiso para editar este descuento.');
            return $this->redirectToRoute('instituto_config_index');
        }

        if ($request->isMethod('POST')) {
            // Verificar tokens antes de editar
            if (!$this->tokenService->hasEnoughTokens($instituto, 'descuento.edit')) {
                $this->addFlash('danger', 'No tienes suficientes tokens para editar un descuento. Balance actual: ' . $this->tokenService->getBalance($instituto)->getBalance());
                return $this->render('instituto_config/descuento_promocional_edit.html.twig', [
                    'descuentoPromocional' => $descuentoPromocional,
                    'configuracion' => $descuentoPromocional->getConfiguracion()
                ]);
            }

            $nombre = $request->request->get('nombre');
            $porcentaje = $request->request->get('porcentaje');
            $activo = $request->request->has('activo');

            $descuentoPromocional->setNombre($nombre);
            $descuentoPromocional->setPorcentaje((float)$porcentaje);
            $descuentoPromocional->setActivo($activo);

            $errors = $validator->validate($descuentoPromocional);
            if (count($errors) === 0) {
                $entityManager->persist($descuentoPromocional);
                $entityManager->flush();
                
                // Consumir tokens después de guardar exitosamente
                $this->tokenService->consumeTokens(
                    $instituto,
                    'descuento.edit',
                    $this->getUser(),
                    'Editar descuento promocional: ' . $descuentoPromocional->getNombre(),
                    'DescuentoPromocional',
                    $descuentoPromocional->getId()
                );
                
                $this->addFlash('success', 'Descuento promocional actualizado correctamente.');
                return $this->redirectToRoute('instituto_config_index', ['tab' => 'descuentos']);
            } else {
                foreach ($errors as $error) {
                    $this->addFlash('danger', $error->getMessage());
                }
            }
        }

        return $this->render('instituto_config/descuento_promocional_edit.html.twig', [
            'descuentoPromocional' => $descuentoPromocional,
            'configuracion' => $descuentoPromocional->getConfiguracion()
        ]);
    }

    /**
     * @Route("/descuento-promocional/{id}", name="instituto_config_descuento_promocional_delete", methods={"POST"})
     */
    public function deleteDescuentoPromocional(Request $request, DescuentoPromocional $descuentoPromocional, EntityManagerInterface $entityManager): Response
    {
        $instituto = $this->getUser()->getInstituto();
        
        // Verificar que el descuento pertenece al instituto del usuario
        if ($descuentoPromocional->getConfiguracion()->getInstituto() !== $instituto) {
            $this->addFlash('danger', 'No tiene permiso para eliminar este descuento.');
            return $this->redirectToRoute('instituto_config_index');
        }

        // Verificar tokens antes de eliminar
        if (!$this->tokenService->hasEnoughTokens($instituto, 'descuento.delete')) {
            $this->addFlash('danger', 'No tienes suficientes tokens para eliminar un descuento. Balance actual: ' . $this->tokenService->getBalance($instituto)->getBalance());
            return $this->redirectToRoute('instituto_config_index', ['tab' => 'descuentos']);
        }

        if ($this->isCsrfTokenValid('delete'.$descuentoPromocional->getId(), $request->request->get('_token'))) {
            $entityManager->remove($descuentoPromocional);
            $entityManager->flush();
            
            // Consumir tokens después de eliminar exitosamente
            $this->tokenService->consumeTokens(
                $instituto,
                'descuento.delete',
                $this->getUser(),
                'Eliminar descuento promocional: ' . $descuentoPromocional->getNombre(),
                'DescuentoPromocional',
                $descuentoPromocional->getId()
            );
            
            $this->addFlash('success', 'Descuento promocional eliminado correctamente.');
        }

        return $this->redirectToRoute('instituto_config_index', ['tab' => 'descuentos']);
    }
} 