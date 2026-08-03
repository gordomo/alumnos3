<?php

namespace App\Controller;

use App\Entity\ConceptoCalificacion;
use App\Entity\Instituto;
use App\Entity\InstitutoConfiguracion;
use App\Entity\Vencimiento;
use App\Entity\DescuentoPromocional;
use App\Entity\MetodoPago;
use App\Repository\ConceptoCalificacionRepository;
use App\Repository\VencimientoRepository;
use App\Repository\InstitutoConfiguracionRepository;
use App\Repository\DescuentoPromocionalRepository;
use App\Repository\MetodoPagoRepository;
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
    public function index(VencimientoRepository $vencimientoRepository, InstitutoConfiguracionRepository $configuracionRepository, DescuentoPromocionalRepository $descuentoPromocionalRepository, MetodoPagoRepository $metodoPagoRepository, ConceptoCalificacionRepository $conceptoRepository): Response
    {
        $instituto = $this->getUser()->getInstituto();
        $vencimientos = $vencimientoRepository->findByInstitutoOrdered($instituto);
        $configuracion = $configuracionRepository->findOrCreateByInstituto($instituto);
        $descuentosPromocionales = $descuentoPromocionalRepository->findByConfiguracion($configuracion);
        usort($descuentosPromocionales, fn($a, $b) => (float) $a->getPorcentaje() <=> (float) $b->getPorcentaje());
        
        $metodosPago = $metodoPagoRepository->findBy(['instituto' => $instituto], ['orden' => 'ASC']);

        return $this->render('instituto_config/index.html.twig', [
            'instituto' => $instituto,
            'vencimientos' => $vencimientos,
            'configuracion' => $configuracion,
            'metodos_pago' => $metodosPago,
            'descuentosPromocionales' => $descuentosPromocionales,
            'conceptosCalificacion' => $conceptoRepository->findByInstituto($instituto, false),
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
        DescuentoPromocionalRepository $descuentoPromocionalRepository,
        MetodoPagoRepository $metodoPagoRepository,
        ConceptoCalificacionRepository $conceptoRepository
    ): Response {
        $instituto = $this->getUser()->getInstituto();
        $configuracion = $configuracionRepository->findOrCreateByInstituto($instituto);
        $vencimientos = $vencimientoRepository->findByInstitutoOrdered($instituto);
        $descuentosPromocionales = $descuentoPromocionalRepository->findByConfiguracion($configuracion);
        usort($descuentosPromocionales, fn($a, $b) => (float) $a->getPorcentaje() <=> (float) $b->getPorcentaje());
        $metodosPago = $metodoPagoRepository->findBy(['instituto' => $instituto], ['orden' => 'ASC']);

        if ($request->isMethod('POST')) {
            $section = $request->request->get('section', 'general');

            // Cada pestaña tiene su propio formulario apuntando a esta misma acción, así
            // que el id del token incluye la sección.
            if (!$this->isCsrfTokenValid('config_edit_' . $section, (string) $request->request->get('_token'))) {
                $this->addFlash('danger', 'Token de seguridad invalido. Volve a intentar.');
                return $this->redirectToRoute('instituto_config_index', ['tab' => $section]);
            }

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

            // Porcentaje mínimo de asistencia para aprobación
            $porcentajeAsistencia = $request->request->get('porcentaje_asistencia_aprobacion');
            $configuracion->setPorcentajeAsistenciaAprobacion(
                $porcentajeAsistencia !== '' && $porcentajeAsistencia !== null ? (float) $porcentajeAsistencia : null
            );

            // Pago total del curso requerido para aprobar
            $configuracion->setRequierePagoTotalParaAprobar($request->request->has('requiere_pago_total_para_aprobar'));

            // Escala de calificación. 'ninguno' deja toda la feature de notas apagada.
            $configuracion->setModoCalificacion((string) $request->request->get('modo_calificacion', InstitutoConfiguracion::MODO_NINGUNO));

            $notaMinima = $request->request->get('nota_minima');
            $configuracion->setNotaMinima($notaMinima !== '' && $notaMinima !== null ? (float) $notaMinima : null);

            $notaMaxima = $request->request->get('nota_maxima');
            $configuracion->setNotaMaxima($notaMaxima !== '' && $notaMaxima !== null ? (float) $notaMaxima : null);

            $notaAprobacion = $request->request->get('nota_aprobacion');
            $configuracion->setNotaAprobacion($notaAprobacion !== '' && $notaAprobacion !== null ? (float) $notaAprobacion : null);

            $configuracion->setNotasInfluyenAprobacion($request->request->has('notas_influyen_aprobacion'));
            $configuracion->setCriterioAprobacionNotas((string) $request->request->get('criterio_aprobacion_notas', InstitutoConfiguracion::CRITERIO_PROMEDIO));

            // Configuración de cuota de inscripción anual
            $configuracion->setCobrarCuotaInscripcionAnual($request->request->has('cobrar_cuota_inscripcion_anual'));
            $montoCuotaInscripcion = $request->request->get('monto_cuota_inscripcion_anual');
            $configuracion->setMontoCuotaInscripcionAnual(
                $montoCuotaInscripcion !== '' && $montoCuotaInscripcion !== null ? (float) $montoCuotaInscripcion : null
            );
            $mesCobroCuotaInscripcion = $request->request->get('mes_cobro_cuota_inscripcion_anual');
            $configuracion->setMesCobroCuotaInscripcionAnual(
                $mesCobroCuotaInscripcion !== '' && $mesCobroCuotaInscripcion !== null ? (int) $mesCobroCuotaInscripcion : 1
            );

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
            'descuentosPromocionales' => $descuentosPromocionales,
            'metodos_pago' => $metodosPago,
            'conceptosCalificacion' => $conceptoRepository->findByInstituto($instituto, false),
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
            if (!$this->isCsrfTokenValid('vencimiento_new', (string) $request->request->get('_token'))) {
                $this->addFlash('danger', 'Token de seguridad invalido. Volve a intentar.');
                return $this->redirectToRoute('instituto_config_index', ['tab' => 'pagos']);
            }

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
            if (!$this->isCsrfTokenValid('vencimiento_edit' . $vencimiento->getId(), (string) $request->request->get('_token'))) {
                $this->addFlash('danger', 'Token de seguridad invalido. Volve a intentar.');
                return $this->redirectToRoute('instituto_config_index', ['tab' => 'pagos']);
            }

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
            if (!$this->isCsrfTokenValid('descuento_new', (string) $request->request->get('_token'))) {
                $this->addFlash('danger', 'Token de seguridad invalido. Volve a intentar.');
                return $this->redirectToRoute('instituto_config_index', ['tab' => 'descuentos']);
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
            if (!$this->isCsrfTokenValid('descuento_edit' . $descuentoPromocional->getId(), (string) $request->request->get('_token'))) {
                $this->addFlash('danger', 'Token de seguridad invalido. Volve a intentar.');
                return $this->redirectToRoute('instituto_config_index', ['tab' => 'descuentos']);
            }

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

    /**
     * @Route("/metodo-pago/new", name="instituto_config_metodo_pago_new", methods={"POST"})
     */
    public function newMetodoPago(Request $request, EntityManagerInterface $entityManager): Response
    {
        if (!$this->isCsrfTokenValid('metodo_pago_new', (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'Token de seguridad invalido. Volve a intentar.');
            return $this->redirectToRoute('instituto_config_index', ['tab' => 'metodos-pago']);
        }

        $instituto = $this->getUser()->getInstituto();
        
        $metodoPago = new MetodoPago();
        $metodoPago->setInstituto($instituto);
        $metodoPago->setNombre($request->request->get('nombre', ''));
        $metodoPago->setActivo(true);
        
        // Obtener el máximo orden actual y sumar 1
        $maxOrden = $entityManager->getRepository(MetodoPago::class)
            ->createQueryBuilder('m')
            ->select('MAX(m.orden)')
            ->where('m.instituto = :instituto')
            ->setParameter('instituto', $instituto)
            ->getQuery()
            ->getSingleScalarResult();
        
        $metodoPago->setOrden(($maxOrden ?? 0) + 1);
        
        $entityManager->persist($metodoPago);
        $entityManager->flush();
        
        $this->addFlash('success', 'Método de pago agregado correctamente.');
        return $this->redirectToRoute('instituto_config_index', ['tab' => 'metodos-pago']);
    }

    /**
     * @Route("/metodo-pago/{id}/edit", name="instituto_config_metodo_pago_edit", methods={"POST"})
     */
    public function editMetodoPago(MetodoPago $metodoPago, Request $request, EntityManagerInterface $entityManager): Response
    {
        if (!$this->isCsrfTokenValid('metodo_pago_edit' . $metodoPago->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'Token de seguridad invalido. Volve a intentar.');
            return $this->redirectToRoute('instituto_config_index', ['tab' => 'metodos-pago']);
        }

        $instituto = $this->getUser()->getInstituto();
        
        if ($metodoPago->getInstituto() !== $instituto) {
            throw $this->createAccessDeniedException();
        }
        
        $metodoPago->setNombre($request->request->get('nombre', $metodoPago->getNombre()));
        $activo = $request->request->get('activo', '0') === '1';

        // "Efectivo" debe permanecer activo para asegurar descuentos y flujo de cobro.
        if (strtolower(trim($metodoPago->getNombre())) === 'efectivo') {
            $activo = true;
        }
        $metodoPago->setActivo($activo);
        
        $entityManager->flush();
        
        $this->addFlash('success', 'Método de pago actualizado correctamente.');
        return $this->redirectToRoute('instituto_config_index', ['tab' => 'metodos-pago']);
    }

    /**
     * @Route("/metodo-pago/{id}/delete", name="instituto_config_metodo_pago_delete", methods={"POST"})
     */
    public function deleteMetodoPago(MetodoPago $metodoPago, Request $request, EntityManagerInterface $entityManager): Response
    {
        $instituto = $this->getUser()->getInstituto();

        if ($metodoPago->getInstituto() !== $instituto) {
            throw $this->createAccessDeniedException();
        }

        // Mismo patrón que deleteVencimiento y deleteDescuentoPromocional.
        if (!$this->isCsrfTokenValid('delete' . $metodoPago->getId(), $request->request->get('_token'))) {
            $this->addFlash('error', 'Token de seguridad inválido. Volvé a intentar.');
            return $this->redirectToRoute('instituto_config_index', ['tab' => 'metodos-pago']);
        }


        // No permitir eliminar "Efectivo" porque es necesario para el descuento
        if (strtolower($metodoPago->getNombre()) === 'efectivo') {
            $this->addFlash('error', 'No se puede eliminar el método de pago "Efectivo" porque es necesario para el sistema de descuentos.');
            return $this->redirectToRoute('instituto_config_index', ['tab' => 'metodos-pago']);
        }
        
        $entityManager->remove($metodoPago);
        $entityManager->flush();
        
        $this->addFlash('success', 'Método de pago eliminado correctamente.');
        return $this->redirectToRoute('instituto_config_index', ['tab' => 'metodos-pago']);
    }

    /**
     * @Route("/concepto-calificacion/new", name="instituto_config_concepto_new", methods={"POST"})
     */
    public function newConceptoCalificacion(
        Request $request,
        EntityManagerInterface $entityManager,
        ConceptoCalificacionRepository $conceptoRepository,
        InstitutoConfiguracionRepository $configuracionRepository
    ): Response {
        $instituto = $this->getUser()->getInstituto();

        if (!$this->isCsrfTokenValid('concepto_new', (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'Token de seguridad inválido.');
            return $this->redirectToRoute('instituto_config_index', ['tab' => 'general']);
        }

        $nombre = trim((string) $request->request->get('nombre'));
        if ($nombre === '') {
            $this->addFlash('danger', 'El nombre del concepto no puede estar vacío.');
            return $this->redirectToRoute('instituto_config_index', ['tab' => 'general']);
        }

        $configuracion = $configuracionRepository->findOneBy(['instituto' => $instituto]);
        if (!$configuracion) {
            $configuracion = new InstitutoConfiguracion();
            $configuracion->setInstituto($instituto);
            $entityManager->persist($configuracion);
        }

        $concepto = new ConceptoCalificacion();
        $concepto->setInstituto($instituto);
        $concepto->setConfiguracion($configuracion);
        $concepto->setNombre($nombre);

        $abreviatura = trim((string) $request->request->get('abreviatura'));
        $concepto->setAbreviatura($abreviatura !== '' ? $abreviatura : null);

        $equivalente = $request->request->get('equivalente_numerico');
        $concepto->setEquivalenteNumerico($equivalente !== '' && $equivalente !== null ? (float) $equivalente : null);

        $concepto->setAprueba($request->request->has('aprueba'));
        $concepto->setOrden($conceptoRepository->siguienteOrden($instituto));

        $entityManager->persist($concepto);
        $entityManager->flush();

        $this->addFlash('success', sprintf('Concepto "%s" agregado a la escala.', $nombre));
        return $this->redirectToRoute('instituto_config_index', ['tab' => 'general']);
    }

    /**
     * @Route("/concepto-calificacion/{id}/edit", name="instituto_config_concepto_edit", methods={"POST"})
     */
    public function editConceptoCalificacion(
        ConceptoCalificacion $concepto,
        Request $request,
        EntityManagerInterface $entityManager
    ): Response {
        $instituto = $this->getUser()->getInstituto();
        if ($concepto->getInstituto() !== $instituto) {
            throw $this->createAccessDeniedException();
        }

        if (!$this->isCsrfTokenValid('concepto_edit' . $concepto->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'Token de seguridad inválido.');
            return $this->redirectToRoute('instituto_config_index', ['tab' => 'general']);
        }

        $nombre = trim((string) $request->request->get('nombre'));
        if ($nombre !== '') {
            $concepto->setNombre($nombre);
        }

        $abreviatura = trim((string) $request->request->get('abreviatura'));
        $concepto->setAbreviatura($abreviatura !== '' ? $abreviatura : null);

        $equivalente = $request->request->get('equivalente_numerico');
        $concepto->setEquivalenteNumerico($equivalente !== '' && $equivalente !== null ? (float) $equivalente : null);

        $concepto->setAprueba($request->request->has('aprueba'));

        $orden = $request->request->get('orden');
        if ($orden !== '' && $orden !== null) {
            $concepto->setOrden((int) $orden);
        }

        $entityManager->flush();

        $this->addFlash('success', 'Concepto actualizado.');
        return $this->redirectToRoute('instituto_config_index', ['tab' => 'general']);
    }

    /**
     * Desactiva un concepto en lugar de borrarlo si tiene notas cargadas: hay
     * calificaciones apuntándolo y borrarlo perdería el significado de esas notas.
     *
     * @Route("/concepto-calificacion/{id}/delete", name="instituto_config_concepto_delete", methods={"POST"})
     */
    public function deleteConceptoCalificacion(
        ConceptoCalificacion $concepto,
        Request $request,
        EntityManagerInterface $entityManager,
        ConceptoCalificacionRepository $conceptoRepository
    ): Response {
        $instituto = $this->getUser()->getInstituto();
        if ($concepto->getInstituto() !== $instituto) {
            throw $this->createAccessDeniedException();
        }

        if (!$this->isCsrfTokenValid('delete' . $concepto->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'Token de seguridad inválido.');
            return $this->redirectToRoute('instituto_config_index', ['tab' => 'general']);
        }

        $usos = $conceptoRepository->contarUsos($concepto);
        if ($usos > 0) {
            $concepto->setActivo(false);
            $entityManager->flush();
            $this->addFlash('warning', sprintf(
                'El concepto "%s" tiene %d nota(s) cargada(s), así que se desactivó en lugar de borrarse. No se va a ofrecer más al calificar, pero las notas existentes lo conservan.',
                $concepto->getNombre(),
                $usos
            ));

            return $this->redirectToRoute('instituto_config_index', ['tab' => 'general']);
        }

        $nombre = $concepto->getNombre();
        $entityManager->remove($concepto);
        $entityManager->flush();

        $this->addFlash('success', sprintf('Concepto "%s" eliminado.', $nombre));
        return $this->redirectToRoute('instituto_config_index', ['tab' => 'general']);
    }
} 