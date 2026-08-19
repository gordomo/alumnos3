<?php

namespace App\Controller;

use App\Entity\Curso;
use App\Entity\Tarea;
use App\Repository\AlumnoCursoHistoricoRepository;
use App\Repository\CursoRepository;
use App\Repository\PeriodoAcademicoRepository;
use App\Repository\TareaEntregaRepository;
use App\Repository\TareaRepository;
use App\Security\Voter\CursoVoter;
use App\Service\InstitutoTimezoneService;
use App\Service\TareaService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lógica compartida entre la pantalla de tareas del administrador y la del profesor.
 *
 * Mismo patrón que AbstractCalificacionController: las subclases solo declaran sus rutas, su
 * rol y qué cursos lista cada una. El aislamiento por curso lo resuelve CursoVoter, que ya
 * exige mismo instituto y, para el profesor, estar asignado al curso.
 */
abstract class AbstractTareaController extends AbstractController
{
    public function __construct(
        protected TareaRepository $tareaRepository,
        protected TareaEntregaRepository $entregaRepository,
        protected CursoRepository $cursoRepository,
        protected PeriodoAcademicoRepository $periodoRepository,
        protected AlumnoCursoHistoricoRepository $historicoRepository,
        protected TareaService $tareaService,
        protected InstitutoTimezoneService $institutoTimezoneService,
        protected EntityManagerInterface $entityManager
    ) {
    }

    /**
     * @return Curso[]
     */
    abstract protected function cursosVisibles(): array;

    abstract protected function rutaBase(): string;

    protected function pantallaIndex(): Response
    {
        $instituto = $this->getUser()->getInstituto();

        return $this->render('tarea/index.html.twig', [
            'cursos' => $this->cursosVisibles(),
            'conteos' => $this->tareaRepository->contarPorCurso($instituto),
            'rutas' => $this->rutas(),
        ]);
    }

    protected function pantallaCurso(Curso $curso): Response
    {
        $this->denyAccessUnlessGranted(CursoVoter::VER_NOTAS, $curso);

        $inscripciones = $this->inscripcionesDelCurso($curso);

        // Resumen por alumno: cuántas entregó de las que se pidieron.
        $resumen = [];
        foreach ($inscripciones as $inscripcion) {
            $resumen[$inscripcion->getId()] = $this->tareaService->resumenParaHistorico($inscripcion);
        }

        return $this->render('tarea/curso.html.twig', [
            'curso' => $curso,
            'tareas' => $this->tareaRepository->findByCurso($curso),
            'inscripciones' => $inscripciones,
            'resumen' => $resumen,
            'puedeAdministrar' => $this->isGranted(CursoVoter::CALIFICAR, $curso),
            'date_format' => $this->institutoTimezoneService->getDateFormatForInstituto($curso->getInstituto()),
            'rutas' => $this->rutas(),
        ]);
    }

    protected function pantallaFormTarea(Request $request, Curso $curso, ?Tarea $tarea): Response
    {
        $this->denyAccessUnlessGranted(CursoVoter::CALIFICAR, $curso);

        $instituto = $curso->getInstituto();
        $periodos = $this->periodoRepository->findByInstituto($instituto);
        $dateFormat = $this->institutoTimezoneService->getDateFormatForInstituto($instituto);

        $esNueva = $tarea === null;
        if ($esNueva) {
            $tarea = new Tarea();
            $tarea->setCurso($curso);
            $tarea->setInstituto($instituto);
            $tarea->setFecha($this->institutoTimezoneService->getCurrentDateForInstituto($instituto));
        }

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('tarea_form' . ($tarea->getId() ?? 'nueva'), (string) $request->request->get('_token'))) {
                $this->addFlash('danger', 'Token de seguridad inválido.');

                return $this->redirectToRoute($this->rutas()['curso'], ['id' => $curso->getId()]);
            }

            $titulo = trim((string) $request->request->get('titulo'));
            if ($titulo === '') {
                $this->addFlash('danger', 'El título de la tarea es obligatorio.');
            } else {
                $tarea->setTitulo($titulo);
                $tarea->setDescripcion($request->request->get('descripcion'));
                $tarea->setFecha($this->fechaDelFormulario($request->request->get('fecha'), $dateFormat, $instituto));

                $fechaEntrega = $this->fechaDelFormulario($request->request->get('fecha_entrega'), $dateFormat, $instituto, true);
                $tarea->setFechaEntrega($fechaEntrega);

                $periodoId = $request->request->get('periodo');
                $tarea->setPeriodo($this->periodoElegido($periodos, $periodoId, $tarea, $esNueva, $instituto));

                if ($esNueva) {
                    $tarea->setCreadoPor($this->getUser());
                    $this->entityManager->persist($tarea);
                } else {
                    $tarea->setUpdatedAt(new \DateTime());
                }

                $this->entityManager->flush();
                $this->addFlash('success', $esNueva ? 'Tarea creada.' : 'Tarea actualizada.');

                return $this->redirectToRoute($this->rutas()['grilla'], ['id' => $tarea->getId()]);
            }
        }

        // Al crearla se propone el período que contiene la fecha, igual que en evaluaciones.
        if ($esNueva && !$tarea->getPeriodo()) {
            $tarea->setPeriodo($this->periodoRepository->findParaFecha($instituto, $tarea->getFecha()));
        }

        return $this->render('tarea/form.html.twig', [
            'curso' => $curso,
            'tarea' => $tarea,
            'esNueva' => $esNueva,
            'periodos' => $periodos,
            'date_format' => $dateFormat,
            'rutas' => $this->rutas(),
        ]);
    }

    protected function pantallaGrilla(Tarea $tarea): Response
    {
        $curso = $tarea->getCurso();
        $this->denyAccessUnlessGranted(CursoVoter::VER_NOTAS, $curso);

        return $this->render('tarea/grilla.html.twig', [
            'tarea' => $tarea,
            'curso' => $curso,
            'inscripciones' => $this->inscripcionesDelCurso($curso),
            'entregas' => $this->entregaRepository->findByTareaIndexadoPorHistorico($tarea),
            'soloLectura' => !$this->isGranted(CursoVoter::CALIFICAR, $curso),
            'date_format' => $this->institutoTimezoneService->getDateFormatForInstituto($curso->getInstituto()),
            'rutas' => $this->rutas(),
        ]);
    }

    protected function accionGuardarGrilla(Request $request, Tarea $tarea): Response
    {
        $curso = $tarea->getCurso();
        $this->denyAccessUnlessGranted(CursoVoter::CALIFICAR, $curso);

        if (!$this->isCsrfTokenValid('tarea_grilla' . $tarea->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'Token de seguridad inválido.');

            return $this->redirectToRoute($this->rutas()['grilla'], ['id' => $tarea->getId()]);
        }

        $resultado = $this->tareaService->guardarGrilla(
            $tarea,
            $this->inscripcionesDelCurso($curso),
            $request->request->all('entregada'),
            $request->request->all('observaciones'),
            $this->getUser()
        );

        $this->addFlash('success', sprintf(
            'Entregas guardadas: %d registrada(s).%s',
            $resultado['guardadas'],
            $resultado['borradas'] > 0 ? sprintf(' %d se destildaron.', $resultado['borradas']) : ''
        ));

        return $this->redirectToRoute($this->rutas()['grilla'], ['id' => $tarea->getId()]);
    }

    protected function accionEliminarTarea(Request $request, Tarea $tarea): Response
    {
        $curso = $tarea->getCurso();
        $this->denyAccessUnlessGranted(CursoVoter::CALIFICAR, $curso);

        if (!$this->isCsrfTokenValid('tarea_eliminar' . $tarea->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'Token de seguridad inválido.');

            return $this->redirectToRoute($this->rutas()['grilla'], ['id' => $tarea->getId()]);
        }

        $titulo = $tarea->getTitulo();
        // Las entregas se van con la tarea por el cascade remove del mapeo.
        $this->entityManager->remove($tarea);
        $this->entityManager->flush();

        $this->addFlash('success', sprintf('Tarea "%s" eliminada, con sus entregas.', $titulo));

        return $this->redirectToRoute($this->rutas()['curso'], ['id' => $curso->getId()]);
    }

    /**
     * Inscripciones activas del curso, que son las filas de la grilla y la lista blanca del
     * guardado.
     *
     * @return \App\Entity\AlumnoCursoHistorico[]
     */
    protected function inscripcionesDelCurso(Curso $curso): array
    {
        $inscripciones = $this->historicoRepository->findBy(['curso' => $curso, 'activo' => true]);

        usort($inscripciones, static function ($a, $b) {
            $alumnoA = $a->getAlumno();
            $alumnoB = $b->getAlumno();

            return strcmp(
                mb_strtolower(($alumnoA ? $alumnoA->getApellido() . $alumnoA->getNombre() : '')),
                mb_strtolower(($alumnoB ? $alumnoB->getApellido() . $alumnoB->getNombre() : ''))
            );
        });

        return $inscripciones;
    }

    /**
     * Interpreta una fecha del formulario con el formato del instituto, y la guarda sin hora,
     * como el resto del proyecto.
     */
    private function fechaDelFormulario(?string $valor, string $dateFormat, $instituto, bool $opcional = false): ?\DateTimeInterface
    {
        $valor = $valor !== null ? trim($valor) : '';

        if ($valor === '') {
            return $opcional ? null : $this->institutoTimezoneService->getCurrentDateForInstituto($instituto);
        }

        $fecha = $this->institutoTimezoneService->parseDateString($valor, $dateFormat);

        if (!$fecha) {
            return $opcional ? null : $this->institutoTimezoneService->getCurrentDateForInstituto($instituto);
        }

        return $this->institutoTimezoneService->normalizeDateOnly($fecha);
    }

    /**
     * El período elegido en el formulario, validando que sea del instituto.
     *
     * @param \App\Entity\PeriodoAcademico[] $periodos
     */
    private function periodoElegido(array $periodos, $periodoId, Tarea $tarea, bool $esNueva, $instituto)
    {
        if ($periodoId === null || $periodoId === '') {
            return $esNueva
                ? $this->periodoRepository->findParaFecha($instituto, $tarea->getFecha())
                : null;
        }

        $elegido = null;
        foreach ($periodos as $periodo) {
            if ((string) $periodo->getId() === (string) $periodoId) {
                $elegido = $periodo;
                break;
            }
        }

        // Un id que no está entre los del instituto se ignora: puede venir editado a mano.
        if (!$elegido) {
            return null;
        }

        // El desplegable viene propuesto según la fecha con la que se abrió el formulario. Si
        // después se escribe otra fecha, esa propuesta queda desactualizada y la tarea terminaba
        // en el período equivocado (una de abril guardada en el trimestre de junio a agosto).
        // Un período de cursada tiene que contener el mes de la tarea; si no lo contiene, la
        // selección era vieja y se reemplaza por el que corresponde. Los de examen se eligen a
        // propósito, así que se respetan tal cual.
        $fecha = $tarea->getFecha();
        if (!$elegido->esExamen() && $fecha && !$elegido->contieneMes((int) $fecha->format('n'))) {
            return $this->periodoRepository->findParaFecha($instituto, $fecha) ?? $elegido;
        }

        return $elegido;
    }

    /**
     * @return array<string, string>
     */
    protected function rutas(): array
    {
        $base = $this->rutaBase();

        return [
            'index' => $base . '_index',
            'curso' => $base . '_curso',
            'nueva' => $base . '_nueva',
            'editar' => $base . '_editar',
            'eliminar' => $base . '_eliminar',
            'grilla' => $base . '_grilla',
            'guardar' => $base . '_guardar',
        ];
    }
}
