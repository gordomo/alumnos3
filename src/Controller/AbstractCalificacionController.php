<?php

namespace App\Controller;

use App\Entity\AlumnoCursoHistorico;
use App\Entity\Curso;
use App\Entity\Evaluacion;
use App\Form\EvaluacionType;
use App\Repository\CalificacionRepository;
use App\Repository\CursoRepository;
use App\Repository\EvaluacionRepository;
use App\Security\Voter\CursoVoter;
use App\Service\CalificacionService;
use App\Service\EscalaCalificacionService;
use App\Service\InstitutoTimezoneService;
use App\Service\NotificationService;
use App\Service\PromedioCalificacionService;
use App\Repository\AreaEvaluacionRepository;
use App\Service\LibretaService;
use App\Repository\PeriodoAcademicoRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lógica compartida entre la pantalla de calificaciones del admin del instituto y la del
 * profesor. Las dos hacen lo mismo; solo cambian el prefijo de ruta, el rol y qué cursos
 * lista cada una.
 *
 * Las subclases declaran sus propias rutas y delegan acá, así se evita la duplicación
 * literal que ya existe en el proyecto entre los dos controllers de asistencias.
 */
abstract class AbstractCalificacionController extends AbstractController
{
    public function __construct(
        protected EvaluacionRepository $evaluacionRepository,
        protected CursoRepository $cursoRepository,
        protected CalificacionService $calificacionService,
        protected EscalaCalificacionService $escalaService,
        protected PromedioCalificacionService $promedioService,
        protected InstitutoTimezoneService $institutoTimezoneService,
        protected EntityManagerInterface $entityManager,
        protected CalificacionRepository $calificacionRepository,
        protected NotificationService $notificationService,
        protected PeriodoAcademicoRepository $periodoRepository,
        protected AreaEvaluacionRepository $areaRepository,
        protected LibretaService $libretaService
    ) {
    }

    /**
     * Cursos que el usuario puede ver en esta pantalla.
     *
     * @return Curso[]
     */
    abstract protected function cursosVisibles(): array;

    /**
     * Prefijo de los nombres de ruta de la subclase, para armar los links de los templates.
     */
    abstract protected function rutaBase(): string;

    /**
     * Si la subclase permite administrar evaluaciones (crear, editar, borrar).
     */
    protected function puedeAdministrarEvaluaciones(): bool
    {
        return true;
    }

    /**
     * Listado de cursos con su cantidad de evaluaciones.
     */
    protected function pantallaIndex(): Response
    {
        $instituto = $this->getUser()->getInstituto();
        if (!$instituto) {
            $this->addFlash('danger', 'No tenés un instituto asignado.');
            return $this->redirectToRoute('app_login');
        }

        if (!$this->escalaService->usaCalificaciones($instituto)) {
            return $this->render('calificacion/sin_escala.html.twig', [
                'rutas' => $this->rutas(),
                'puedeConfigurar' => $this->isGranted('ROLE_ADMIN_INSTITUTO'),
            ]);
        }

        $cursos = $this->cursosVisibles();

        return $this->render('calificacion/index.html.twig', [
            'cursos' => $cursos,
            'evaluacionesPorCurso' => $this->evaluacionRepository->contarPorCursos($cursos),
            'rutas' => $this->rutas(),
            'date_format' => $this->institutoTimezoneService->getDateFormatForInstituto($instituto),
        ]);
    }

    /**
     * Evaluaciones de un curso más el resumen de promedios por alumno.
     */
    protected function pantallaCurso(Curso $curso): Response
    {
        $this->denyAccessUnlessGranted(CursoVoter::VER_NOTAS, $curso);

        $evaluaciones = $this->evaluacionRepository->findByCurso($curso);
        $resumenes = $this->promedioService->calcularParaCurso($curso);

        $filas = [];
        foreach ($this->calificacionService->getInscripciones($curso) as $fila) {
            $historicoId = $fila['historico']->getId();
            $filas[] = [
                'alumno' => $fila['alumno'],
                'historico' => $fila['historico'],
                'cargable' => $fila['cargable'],
                'resumen' => $resumenes[$historicoId] ?? $this->promedioService->resumenVacio(),
            ];
        }

        return $this->render('calificacion/curso.html.twig', [
            'curso' => $curso,
            'evaluaciones' => $evaluaciones,
            'filas' => $filas,
            'escala' => $this->escalaService->getEscalaParaInstituto($curso->getInstituto()),
            'rutas' => $this->rutas(),
            'puedeAdministrar' => $this->puedeAdministrarEvaluaciones(),
            'puedeCalificar' => $this->isGranted(CursoVoter::CALIFICAR, $curso),
            'date_format' => $this->institutoTimezoneService->getDateFormatForInstituto($curso->getInstituto()),
        ]);
    }

    /**
     * Alta y edición de una evaluación.
     */
    protected function pantallaFormEvaluacion(Request $request, Curso $curso, ?Evaluacion $evaluacion): Response
    {
        $this->denyAccessUnlessGranted(CursoVoter::CALIFICAR, $curso);

        if (!$this->puedeAdministrarEvaluaciones()) {
            throw $this->createAccessDeniedException('No podés administrar evaluaciones.');
        }

        $instituto = $curso->getInstituto();
        $periodos = $this->periodoRepository->findByInstituto($instituto);
        $areas = $this->areaRepository->findByInstituto($instituto);

        $esNueva = $evaluacion === null;
        if ($esNueva) {
            $evaluacion = new Evaluacion();
            $evaluacion->setCurso($curso);
            $evaluacion->setInstituto($instituto);
            $evaluacion->setFecha($this->institutoTimezoneService->getCurrentDateForInstituto($instituto));
            // Se propone el período que contiene la fecha. Los de examen no se proponen: su
            // mes se solapa con el de un trimestre, así que el profesor los elige a mano.
            $evaluacion->setPeriodo($this->periodoRepository->findParaFecha($instituto, $evaluacion->getFecha()));
        }

        $dateFormat = $this->institutoTimezoneService->getDateFormatForInstituto($instituto);
        $form = $this->createForm(EvaluacionType::class, $evaluacion, [
            'date_format' => $dateFormat,
            'curso' => $curso,
            'periodos' => $periodos,
            'areas' => $areas,
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // Fecha sin hora, a medianoche UTC, como el resto del proyecto.
            $evaluacion->setFecha($this->institutoTimezoneService->normalizeDateOnly($evaluacion->getFecha()));

            if ($esNueva) {
                // Congela la escala vigente para que un cambio posterior de configuración
                // no reinterprete las notas de esta evaluación.
                $this->escalaService->aplicarSnapshot($evaluacion);
                $evaluacion->setCreadoPor($this->getUser());
                $this->entityManager->persist($evaluacion);
            } else {
                $evaluacion->setUpdatedAt(new \DateTime());
            }

            $this->entityManager->flush();
            $this->addFlash('success', $esNueva ? 'Evaluación creada.' : 'Evaluación actualizada.');

            return $this->redirectToRoute($this->rutas()['grilla'], ['id' => $evaluacion->getId()]);
        }

        return $this->renderForm('calificacion/evaluacion_form.html.twig', [
            'curso' => $curso,
            'evaluacion' => $evaluacion,
            'form' => $form,
            'esNueva' => $esNueva,
            'rutas' => $this->rutas(),
            'date_format' => $dateFormat,
        ]);
    }

    /**
     * Grilla de carga de notas de una evaluación.
     */
    protected function pantallaGrilla(Evaluacion $evaluacion): Response
    {
        $curso = $evaluacion->getCurso();
        $this->denyAccessUnlessGranted(CursoVoter::VER_NOTAS, $curso);

        return $this->render('calificacion/grilla.html.twig', [
            'curso' => $curso,
            'evaluacion' => $evaluacion,
            'filas' => $this->calificacionService->getGrilla($evaluacion),
            'escala' => $this->escalaService->getEscalaParaEvaluacion($evaluacion),
            'soloLectura' => !$this->isGranted(CursoVoter::CALIFICAR, $curso),
            'rutas' => $this->rutas(),
            'puedeAdministrar' => $this->puedeAdministrarEvaluaciones(),
            'date_format' => $this->institutoTimezoneService->getDateFormatForInstituto($curso->getInstituto()),
        ]);
    }

    /**
     * Guardado de la grilla.
     */
    protected function accionGuardarGrilla(Request $request, Evaluacion $evaluacion): Response
    {
        $curso = $evaluacion->getCurso();
        $this->denyAccessUnlessGranted(CursoVoter::CALIFICAR, $curso);

        if (!$this->isCsrfTokenValid('calificar_' . $evaluacion->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'Token de seguridad inválido. Volvé a intentar.');
            return $this->redirectToRoute($this->rutas()['grilla'], ['id' => $evaluacion->getId()]);
        }

        $resultado = $this->calificacionService->guardarGrilla(
            $evaluacion,
            $request->request->all('notas'),
            $request->request->all('conceptos'),
            $request->request->all('observaciones'),
            $request->request->all('ausentes'),
            $request->request->all('historicos_procesados'),
            $this->getUser()
        );

        $tocadas = $resultado['guardadas'] + $resultado['actualizadas'];
        if ($tocadas > 0 || $resultado['eliminadas'] > 0) {
            $this->addFlash('success', sprintf(
                '%d nota(s) guardada(s), %d eliminada(s).',
                $tocadas,
                $resultado['eliminadas']
            ));
        } elseif (!$resultado['errores']) {
            $this->addFlash('info', 'No hubo cambios para guardar.');
        }

        foreach ($resultado['errores'] as $error) {
            $this->addFlash('warning', $error);
        }

        return $this->redirectToRoute($this->rutas()['grilla'], ['id' => $evaluacion->getId()]);
    }

    /**
     * Borrado de una evaluación, con sus notas.
     */
    protected function accionEliminarEvaluacion(Request $request, Evaluacion $evaluacion): Response
    {
        $curso = $evaluacion->getCurso();
        $this->denyAccessUnlessGranted(CursoVoter::CALIFICAR, $curso);

        if (!$this->puedeAdministrarEvaluaciones()) {
            throw $this->createAccessDeniedException('No podés administrar evaluaciones.');
        }

        if (!$this->isCsrfTokenValid('eliminar_evaluacion_' . $evaluacion->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'Token de seguridad inválido.');
            return $this->redirectToRoute($this->rutas()['grilla'], ['id' => $evaluacion->getId()]);
        }

        $cursoId = $curso->getId();
        $cantidad = count($evaluacion->getCalificaciones());
        $this->entityManager->remove($evaluacion);
        $this->entityManager->flush();

        $this->addFlash('success', sprintf('Evaluación eliminada junto con %d nota(s).', $cantidad));

        return $this->redirectToRoute($this->rutas()['curso'], ['id' => $cursoId]);
    }

    /**
     * Boletín de un alumno en un curso.
     */
    protected function pantallaBoletin(AlumnoCursoHistorico $historico): Response
    {
        $curso = $historico->getCurso();
        $this->denyAccessUnlessGranted(CursoVoter::VER_NOTAS, $curso);

        $calificaciones = $this->calificacionRepository->findByHistorico($historico);

        return $this->render('calificacion/boletin.html.twig', [
            'curso' => $curso,
            'historico' => $historico,
            'alumno' => $historico->getAlumno(),
            'calificaciones' => $calificaciones,
            'resumen' => $this->promedioService->resumir(
                $calificaciones,
                $this->criterioDelInstituto($curso)
            ),
            'rutas' => $this->rutas(),
            'date_format' => $this->institutoTimezoneService->getDateFormatForInstituto($curso->getInstituto()),
        ]);
    }

    /**
     * Libreta del alumno: la grilla de áreas por período, lista para imprimir.
     *
     * Comparte permiso con el boletín (VER_NOTAS): es la misma información, presentada como la
     * libreta de papel que ya usan los institutos.
     */
    protected function pantallaLibreta(AlumnoCursoHistorico $historico): Response
    {
        $curso = $historico->getCurso();
        $this->denyAccessUnlessGranted(CursoVoter::VER_NOTAS, $curso);

        $instituto = $curso->getInstituto();
        $configuracion = $instituto->getConfiguracion();

        return $this->render('calificacion/libreta.html.twig', $this->libretaService->construir($historico) + [
            'instituto' => $instituto,
            'configuracion' => $configuracion,
            'rutas' => $this->rutas(),
            'date_format' => $this->institutoTimezoneService->getDateFormatForInstituto($instituto),
        ]);
    }

    /**
     * Envía el boletín por email. Por defecto al tutor del alumno.
     */
    protected function accionEnviarBoletin(Request $request, AlumnoCursoHistorico $historico): Response
    {
        $curso = $historico->getCurso();
        $this->denyAccessUnlessGranted(CursoVoter::VER_NOTAS, $curso);

        if (!$this->isCsrfTokenValid('boletin_' . $historico->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'Token de seguridad inválido.');
            return $this->redirectToRoute($this->rutas()['boletin'], ['id' => $historico->getId()]);
        }

        $calificaciones = $this->calificacionRepository->findByHistorico($historico);
        if (!$calificaciones) {
            $this->addFlash('warning', 'No hay notas cargadas para enviar.');
            return $this->redirectToRoute($this->rutas()['boletin'], ['id' => $historico->getId()]);
        }

        $destino = trim((string) $request->request->get('email_destino')) ?: null;

        $enviado = $this->notificationService->enviarBoletinNotas(
            $historico,
            $calificaciones,
            $this->promedioService->resumir($calificaciones, $this->criterioDelInstituto($curso)),
            $destino,
            $this->getUser()
        );

        if ($enviado) {
            $this->addFlash('success', 'Boletín enviado. Podés ver el registro en Historial de Emails.');
        } else {
            $this->addFlash('danger', 'No se pudo enviar el boletín. Revisá el email de destino en Historial de Emails.');
        }

        return $this->redirectToRoute($this->rutas()['boletin'], ['id' => $historico->getId()]);
    }

    /**
     * Criterio de aprobación por notas del instituto del curso.
     */
    protected function criterioDelInstituto(Curso $curso): string
    {
        $config = $curso->getInstituto() ? $curso->getInstituto()->getConfiguracion() : null;

        return $config ? $config->getCriterioAprobacionNotas() : 'promedio';
    }

    /**
     * Nombres de ruta de la subclase, para que los templates sean compartidos.
     *
     * @return array<string, string>
     */
    protected function rutas(): array
    {
        $base = $this->rutaBase();

        return [
            'index' => $base . '_index',
            'curso' => $base . '_curso',
            'grilla' => $base . '_grilla',
            'guardar' => $base . '_guardar',
            'nueva' => $base . '_evaluacion_nueva',
            'editar' => $base . '_evaluacion_editar',
            'eliminar' => $base . '_evaluacion_eliminar',
            'boletin' => $base . '_boletin',
            'libreta' => $base . '_libreta',
            'boletin_email' => $base . '_boletin_email',
        ];
    }
}
