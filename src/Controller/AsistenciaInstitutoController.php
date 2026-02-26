<?php

namespace App\Controller;

use App\Entity\AsistenciaAlumnos;
use App\Entity\Alumno;
use App\Repository\AsistenciaAlumnosRepository;
use App\Repository\CursoRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use App\Service\InstitutoTimezoneService;

/**
 * @Route("/instituto/asistencias")
 */
class AsistenciaInstitutoController extends AbstractController
{
    public function __construct(
        private LoggerInterface $logger,
        private InstitutoTimezoneService $institutoTimezoneService
    ) {
    }

    /**
     * @Route("/", name="app_instituto_asistencias_index", methods={"GET", "POST"})
     */
    public function index(
        Request $request,
        AsistenciaAlumnosRepository $asistenciaRepository,
        CursoRepository $cursoRepository,
        EntityManagerInterface $entityManager
    ): Response
    {
        $instituto = $this->getUser()->getInstituto();
        $dateFormat = $this->institutoTimezoneService->getDateFormatForInstituto($instituto);
        $nowInstituto = $this->institutoTimezoneService->getNowForInstituto($instituto);
        $fechaHoyStr = $nowInstituto->format($dateFormat);

        // Obtener fecha del formulario (puede venir en formato del instituto o Y-m-d) o usar hoy
        $fechaRequest = $request->get('fecha', $fechaHoyStr);
        $fechaObj = $this->institutoTimezoneService->parseDateString($fechaRequest);
        if (!$fechaObj) {
            $fechaObj = $nowInstituto;
        }
        $fecha = $fechaObj->format($dateFormat);
        $fechaYmd = $fechaObj->format('Y-m-d');
        $cursoId = $request->get('curso');
        // Obtener modo de GET o POST (el formulario puede enviarlo en POST)
        $modo = $request->get('modo') ?? $request->request->get('modo', 'edicion'); // 'edicion' o 'lectura'
        
        // Obtener todos los cursos del instituto
        $cursos = $cursoRepository->findBy(['instituto' => $instituto]);
        
        // Si se seleccionó un curso, obtener las asistencias de ese curso para la fecha seleccionada
        if ($cursoId) {
            $curso = $cursoRepository->find($cursoId);
            
            // Verificar que el curso pertenece al instituto del usuario
            if ($curso->getInstituto() !== $instituto) {
                throw $this->createAccessDeniedException('No tiene acceso a este curso.');
            }

            // Si es una petición POST, procesar el formulario de asistencia
            if ($request->isMethod('POST')) {
                $this->logger->info('POST recibido en AsistenciaInstitutoController', [
                    'fecha' => $fecha,
                    'curso_id' => $cursoId,
                    'modo' => $modo,
                    'post_data' => $request->request->all()
                ]);
                
                // Asegurar que el modo se obtiene del POST si está disponible
                $modoPost = $request->request->get('modo', $modo);
                $this->logger->info('Modo obtenido', ['modo_post' => $modoPost, 'modo_get' => $modo]);
                
                if ($modoPost !== 'edicion') {
                    $this->logger->warning('Modo no es edicion', ['modo' => $modoPost]);
                    throw $this->createAccessDeniedException('No tiene permiso para editar asistencias.');
                }
                
                $asistencias = $request->request->all('asistencias') ?? [];
                $observaciones = $request->request->all('observaciones') ?? [];
                $alumnosProcesados = $request->request->all('alumnos_procesados') ?? [];
                
                $this->logger->info('Datos recibidos', [
                    'asistencias_count' => count($asistencias),
                    'observaciones_count' => count($observaciones),
                    'alumnos_procesados_count' => count($alumnosProcesados),
                    'asistencias' => $asistencias,
                    'alumnos_procesados' => $alumnosProcesados
                ]);
                
                $fechaAsistencia = $fechaObj;

                // Obtener todos los alumnos del curso
                $alumnos = $curso->getAlumnos();
                
                $this->logger->info('Alumnos del curso', ['count' => $alumnos->count()]);
                
                if ($alumnos->isEmpty()) {
                    $this->logger->warning('No hay alumnos en el curso', ['curso_id' => $cursoId]);
                    $this->addFlash('warning', 'No hay alumnos en este curso.');
                    return $this->redirectToRoute('app_instituto_asistencias_index', [
                        'fecha' => $fecha,
                        'curso' => $cursoId,
                        'modo' => $modo
                    ]);
                }
                
                $alumnosProcesadosIds = array_map('intval', $alumnosProcesados);
                $asistenciasCreadas = 0;
                $asistenciasActualizadas = 0;
                $asistenciasEliminadas = 0;
                
                foreach ($alumnos as $alumno) {
                    $alumnoId = $alumno->getId();
                    
                    // Solo procesar si el alumno está en la lista de procesados
                    if (!in_array($alumnoId, $alumnosProcesadosIds)) {
                        continue;
                    }
                    
                    $valorAsistencia = $asistencias[$alumnoId] ?? 'sin_registro';
                    
                    // Buscar asistencia existente
                    $asistenciaExistente = $asistenciaRepository->findOneBy([
                        'alumno' => $alumno,
                        'curso' => $curso,
                        'fecha' => $fechaAsistencia
                    ]);
                    
                    if ($valorAsistencia === 'sin_registro') {
                        if ($asistenciaExistente) {
                            $entityManager->remove($asistenciaExistente);
                            $asistenciasEliminadas++;
                            $this->logger->debug('Eliminando asistencia (restablecer a sin registro)', ['alumno_id' => $alumnoId]);
                        }
                        continue;
                    }
                    
                    $presente = ($valorAsistencia === '1' || $valorAsistencia === 1);
                    
                    if ($asistenciaExistente) {
                        $asistenciaExistente->setPresente($presente);
                        $asistenciaExistente->setObservaciones($observaciones[$alumnoId] ?? '');
                        $asistenciasActualizadas++;
                        
                        $this->logger->debug('Actualizando asistencia', [
                            'alumno_id' => $alumnoId,
                            'presente' => $presente,
                            'observaciones' => $observaciones[$alumnoId] ?? ''
                        ]);
                    } else {
                        $asistencia = new AsistenciaAlumnos();
                        $asistencia->setAlumno($alumno);
                        $asistencia->setCurso($curso);
                        $asistencia->setFecha($fechaAsistencia);
                        $asistencia->setPresente($presente);
                        $asistencia->setObservaciones($observaciones[$alumnoId] ?? '');
                        
                        $entityManager->persist($asistencia);
                        $asistenciasCreadas++;
                        
                        $this->logger->debug('Creando asistencia', [
                            'alumno_id' => $alumnoId,
                            'presente' => $presente,
                            'observaciones' => $observaciones[$alumnoId] ?? ''
                        ]);
                    }
                }

                $this->logger->info('Asistencias preparadas para guardar', [
                    'creadas' => $asistenciasCreadas,
                    'actualizadas' => $asistenciasActualizadas
                ]);

                try {
                    $entityManager->flush();
                    $totalProcesadas = $asistenciasCreadas + $asistenciasActualizadas + $asistenciasEliminadas;
                    $this->logger->info('Asistencias guardadas exitosamente', [
                        'creadas' => $asistenciasCreadas,
                        'actualizadas' => $asistenciasActualizadas,
                        'eliminadas' => $asistenciasEliminadas,
                        'total' => $totalProcesadas
                    ]);
                    
                    $mensaje = 'Asistencias guardadas correctamente';
                    if ($totalProcesadas > 0) {
                        $mensaje .= sprintf(' (%d registros)', $totalProcesadas);
                    }
                    $this->addFlash('success', $mensaje . '.');
                } catch (\Exception $e) {
                    $this->logger->error('Error al guardar asistencias', [
                        'message' => $e->getMessage(),
                        'trace' => $e->getTraceAsString()
                    ]);
                    $this->addFlash('error', 'Error al guardar las asistencias: ' . $e->getMessage());
                }
                
                return $this->redirectToRoute('app_instituto_asistencias_index', [
                    'fecha' => $fecha,
                    'curso' => $cursoId,
                    'modo' => $modo
                ]);
            }
            
            // Obtener todos los alumnos del curso
            $alumnos = $curso->getAlumnos();
            
            // Crear un array con todos los alumnos y su estado de asistencia
            $asistenciasPorAlumno = [];
            $fechaBusqueda = $fechaObj;
            foreach ($alumnos as $alumno) {
                $asistencia = $asistenciaRepository->findOneBy([
                    'alumno' => $alumno,
                    'curso' => $curso,
                    'fecha' => $fechaBusqueda
                ]);
                
                $presente = $asistencia ? $asistencia->getPresente() : null;
                
                $this->logger->debug('Cargando asistencia para vista', [
                    'alumno_id' => $alumno->getId(),
                    'fecha' => $fecha,
                    'asistencia_encontrada' => $asistencia !== null,
                    'presente' => $presente,
                    'asistencia_id' => $asistencia ? $asistencia->getId() : null
                ]);
                
                $asistenciasPorAlumno[] = [
                    'alumno' => $alumno,
                    'presente' => $presente,
                    'observaciones' => $asistencia ? $asistencia->getObservaciones() : ''
                ];
            }
            
            // Validar si la fecha está configurada para el curso
            $fechaValida = $this->validarFechaCurso($curso, $fechaYmd);
            
            return $this->render('asistencia_instituto/index.html.twig', [
                'asistenciasPorAlumno' => $asistenciasPorAlumno,
                'fecha' => $fecha,
                'fechaHoy' => $fechaHoyStr,
                'date_format' => $dateFormat,
                'cursos' => $cursos,
                'cursoSeleccionado' => $curso,
                'modo' => $modo,
                'fechaValida' => $fechaValida
            ]);
        }

        // Si no se seleccionó un curso, mostrar la lista de cursos
        return $this->render('asistencia_instituto/index.html.twig', [
            'fecha' => $fecha,
            'fechaHoy' => $fechaHoyStr,
            'date_format' => $dateFormat,
            'cursos' => $cursos,
            'cursoSeleccionado' => null,
            'modo' => $modo
        ]);
    }

    /**
     * @Route("/informe", name="app_instituto_asistencias_informe", methods={"GET"})
     */
    public function informe(
        Request $request,
        AsistenciaAlumnosRepository $asistenciaRepository,
        CursoRepository $cursoRepository
    ): Response
    {
        $instituto = $this->getUser()->getInstituto();
        $dateFormat = $this->institutoTimezoneService->getDateFormatForInstituto($instituto);
        $fechaHoyStr = $this->institutoTimezoneService->getNowForInstituto($instituto)->format($dateFormat);

        // Obtener parámetros de búsqueda (fecha puede venir en formato del instituto)
        $cursoId = $request->get('curso');
        $tipoInforme = $request->get('tipo', 'dia'); // dia, semana, mes
        $fechaRequest = $request->get('fecha', $fechaHoyStr);
        $fechaObj = $this->institutoTimezoneService->parseDateString($fechaRequest);
        if (!$fechaObj) {
            $fechaObj = $this->institutoTimezoneService->getNowForInstituto($instituto);
        }
        $fecha = $fechaObj->format($dateFormat);

        // Obtener todos los cursos del instituto
        $cursos = $cursoRepository->findBy(['instituto' => $instituto]);
        
        // Inicializar variables
        $curso = null;
        $informeAsistencias = [];
        $fechaInicio = null;
        $fechaFin = null;
        
        if ($cursoId) {
            $curso = $cursoRepository->find($cursoId);
            
            // Verificar que el curso pertenece al instituto del usuario
            if ($curso->getInstituto() !== $instituto) {
                throw $this->createAccessDeniedException('No tiene acceso a este curso.');
            }
            
            // Calcular fechas según tipo de informe
            
            switch ($tipoInforme) {
                case 'semana':
                    // Obtener el primer día de la semana (lunes)
                    $diaSemana = $fechaObj->format('N');
                    $diasAtras = $diaSemana - 1;
                    $fechaInicio = clone $fechaObj;
                    $fechaInicio->modify("-$diasAtras days");
                    
                    // Obtener el último día de la semana (domingo)
                    $fechaFin = clone $fechaInicio;
                    $fechaFin->modify('+6 days');
                    break;
                    
                case 'mes':
                    // Primer día del mes
                    $fechaInicio = new \DateTime($fechaObj->format('Y-m-01'));
                    
                    // Último día del mes
                    $fechaFin = new \DateTime($fechaObj->format('Y-m-t'));
                    break;
                    
                default: // 'dia'
                    $fechaInicio = clone $fechaObj;
                    $fechaFin = clone $fechaObj;
            }
            
            // Obtener asistencias para el rango de fechas
            $asistencias = $asistenciaRepository->findByDateRange(
                $curso,
                $fechaInicio,
                $fechaFin
            );
            
            // Obtener todos los alumnos del curso
            $alumnos = $curso->getAlumnos();
            
            // Preparar estructura para mostrar el informe según el tipo
            if ($tipoInforme === 'dia') {
                // Para informe diario, similar a la vista normal
                foreach ($alumnos as $alumno) {
                    $asistencia = $asistenciaRepository->findOneBy([
                        'alumno' => $alumno,
                        'curso' => $curso,
                        'fecha' => $fechaObj
                    ]);
                    
                    $informeAsistencias[] = [
                        'alumno' => $alumno,
                        'fecha' => $fechaObj->format('Y-m-d'),
                        'presente' => $asistencia ? $asistencia->getPresente() : null,
                        'observaciones' => $asistencia ? $asistencia->getObservaciones() : ''
                    ];
                }
            } else {
                // Para informes semanales y mensuales, mostrar todas las fechas
                // Crear un array por alumno con todas las fechas
                $asistenciasPorAlumno = [];
                $fechasArray = [];
                
                // Generar todas las fechas en el rango
                $fechaIterator = clone $fechaInicio;
                while ($fechaIterator <= $fechaFin) {
                    $fechasArray[] = $fechaIterator->format('Y-m-d');
                    $fechaIterator->modify('+1 day');
                }
                
                // Inicializar la estructura para todos los alumnos
                foreach ($alumnos as $alumno) {
                    $asistenciasPorAlumno[$alumno->getId()] = [
                        'alumno' => $alumno,
                        'fechas' => []
                    ];
                    
                    foreach ($fechasArray as $fechaStr) {
                        $asistenciasPorAlumno[$alumno->getId()]['fechas'][$fechaStr] = [
                            'presente' => null,
                            'observaciones' => ''
                        ];
                    }
                }
                
                // Rellenar con los datos reales de asistencia
                foreach ($asistencias as $asistencia) {
                    $alumnoId = $asistencia->getAlumno()->getId();
                    $fechaStr = $asistencia->getFecha()->format('Y-m-d');
                    
                    if (isset($asistenciasPorAlumno[$alumnoId]) && isset($asistenciasPorAlumno[$alumnoId]['fechas'][$fechaStr])) {
                        $asistenciasPorAlumno[$alumnoId]['fechas'][$fechaStr] = [
                            'presente' => $asistencia->getPresente(),
                            'observaciones' => $asistencia->getObservaciones()
                        ];
                    }
                }
                
                $informeAsistencias = [
                    'fechas' => $fechasArray,
                    'alumnos' => array_values($asistenciasPorAlumno)
                ];
            }
        }
        
        return $this->render('asistencia_instituto/informe.html.twig', [
            'cursos' => $cursos,
            'curso' => $curso,
            'tipoInforme' => $tipoInforme,
            'fecha' => $fecha,
            'fechaHoy' => $fechaHoyStr,
            'date_format' => $dateFormat,
            'fechaInicio' => $fechaInicio ? $fechaInicio->format('Y-m-d') : null,
            'fechaFin' => $fechaFin ? $fechaFin->format('Y-m-d') : null,
            'informeAsistencias' => $informeAsistencias
        ]);
    }

    /**
     * @Route("/exportar/{formato}", name="app_instituto_asistencias_exportar", methods={"GET"})
     */
    public function exportar(
        Request $request,
        AsistenciaAlumnosRepository $asistenciaRepository,
        CursoRepository $cursoRepository,
        string $formato = 'pdf'
    ): Response
    {
        $instituto = $this->getUser()->getInstituto();
        $fechaHoyStr = $this->institutoTimezoneService->getNowForInstituto($instituto)->format(
            $this->institutoTimezoneService->getDateFormatForInstituto($instituto)
        );

        // Obtener los mismos parámetros que en el informe (fecha puede venir en formato del instituto)
        $cursoId = $request->get('curso');
        $tipoInforme = $request->get('tipo', 'dia');
        $fechaRequest = $request->get('fecha', $fechaHoyStr);
        $fechaObj = $this->institutoTimezoneService->parseDateString($fechaRequest);
        if (!$fechaObj) {
            $fechaObj = $this->institutoTimezoneService->getNowForInstituto($instituto);
        }
        $fecha = $fechaObj->format('Y-m-d');
        
        // Validar formato
        if (!in_array($formato, ['pdf', 'excel', 'csv'])) {
            throw $this->createNotFoundException('Formato no soportado');
        }
        
        // Lógica similar a la del método informe para obtener los datos
        // Aquí implementaríamos la exportación según el formato...
        
        // Por ahora, retornamos un mensaje indicando que se implementará
        return $this->render('asistencia_instituto/exportar.html.twig', [
            'formato' => $formato,
            'mensaje' => 'La función de exportación estará disponible próximamente.'
        ]);
    }

    /**
     * Valida si una fecha está configurada para un curso
     * Verifica que la fecha esté dentro del rango del curso y que coincida con los días configurados
     * 
     * @param \App\Entity\Curso $curso
     * @param string $fecha Fecha en formato Y-m-d
     * @return array ['valida' => bool, 'mensaje' => string]
     */
    private function validarFechaCurso($curso, string $fecha): array
    {
        $fechaObj = new \DateTime($fecha);
        
        // Verificar si el curso tiene configuración de fechas
        if (!$curso->getFechaInicio() || !$curso->getFechaFin()) {
            return [
                'valida' => false,
                'mensaje' => 'El curso no tiene fechas de inicio y fin configuradas.'
            ];
        }
        
        // Verificar si la fecha está dentro del rango del curso
        if ($fechaObj < $curso->getFechaInicio() || $fechaObj > $curso->getFechaFin()) {
            return [
                'valida' => false,
                'mensaje' => sprintf(
                    'La fecha seleccionada está fuera del rango del curso (del %s al %s).',
                    $curso->getFechaInicio()->format($this->institutoTimezoneService->getDateFormatForInstituto($curso->getInstituto())),
                    $curso->getFechaFin()->format($this->institutoTimezoneService->getDateFormatForInstituto($curso->getInstituto()))
                )
            ];
        }
        
        // Verificar si el curso tiene días configurados
        $diasCurso = $curso->getDias();
        if (empty($diasCurso)) {
            return [
                'valida' => false,
                'mensaje' => 'El curso no tiene días de la semana configurados.'
            ];
        }
        
        // Mapeo de días de la semana en español
        $diasSemana = [
            'Domingo' => 0,
            'Lunes' => 1,
            'Martes' => 2,
            'Miercoles' => 3,
            'Jueves' => 4,
            'Viernes' => 5,
            'Sabado' => 6
        ];
        
        // Obtener el día de la semana de la fecha (0 = Domingo, 1 = Lunes, etc.)
        $diaSemanaFecha = (int)$fechaObj->format('w');
        
        // Convertir los días del curso a números
        $diasCursoNumeros = array_map(function($dia) use ($diasSemana) {
            return $diasSemana[$dia] ?? null;
        }, $diasCurso);
        
        // Verificar si el día de la semana de la fecha está en los días configurados
        if (!in_array($diaSemanaFecha, $diasCursoNumeros)) {
            $diasTexto = implode(', ', $diasCurso);
            return [
                'valida' => false,
                'mensaje' => sprintf(
                    'La fecha seleccionada (%s) no coincide con los días configurados para este curso (%s).',
                    $this->obtenerNombreDia($diaSemanaFecha),
                    $diasTexto
                )
            ];
        }
        
        return [
            'valida' => true,
            'mensaje' => ''
        ];
    }

    /**
     * Obtiene el nombre del día de la semana en español
     * 
     * @param int $diaSemana 0 = Domingo, 1 = Lunes, etc.
     * @return string
     */
    private function obtenerNombreDia(int $diaSemana): string
    {
        $dias = [
            0 => 'Domingo',
            1 => 'Lunes',
            2 => 'Martes',
            3 => 'Miércoles',
            4 => 'Jueves',
            5 => 'Viernes',
            6 => 'Sábado'
        ];
        
        return $dias[$diaSemana] ?? 'Desconocido';
    }
} 