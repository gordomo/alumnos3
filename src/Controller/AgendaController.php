<?php

namespace App\Controller;

use App\Entity\Curso;
use App\Entity\EventoAgenda;
use App\Repository\CursoRepository;
use App\Repository\EventoAgendaRepository;
use App\Service\AgendaService;
use App\Service\InstitutoTimezoneService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Agenda del instituto, con la carga de los eventos propios.
 *
 * El prefijo /instituto ya exige ROLE_ADMIN_INSTITUTO en access_control.
 *
 * @Route("/instituto/agenda")
 */
#[IsGranted('ROLE_ADMIN_INSTITUTO')]
class AgendaController extends AbstractAgendaController
{
    protected function rutas(): array
    {
        return ['index' => 'app_agenda_index', 'eventos' => 'app_agenda_eventos'];
    }

    /**
     * @Route("", name="app_agenda_index", methods={"GET"})
     */
    public function index(): Response
    {
        return $this->pantalla();
    }

    /**
     * @Route("/eventos", name="app_agenda_eventos", methods={"GET"})
     */
    public function eventos(Request $request): JsonResponse
    {
        return $this->eventosJson($request);
    }

    /**
     * @Route("/evento/nuevo", name="app_agenda_evento_nuevo", methods={"GET", "POST"})
     */
    public function nuevo(
        Request $request,
        CursoRepository $cursoRepository,
        EntityManagerInterface $entityManager,
        ValidatorInterface $validator,
        InstitutoTimezoneService $institutoTimezoneService
    ): Response {
        $evento = new EventoAgenda();
        $evento->setInstituto($this->getUser()->getInstituto());
        $evento->setFechaInicio($institutoTimezoneService->getNowForInstituto($this->getUser()->getInstituto()));

        return $this->formulario($request, $evento, $cursoRepository, $entityManager, $validator, true);
    }

    /**
     * @Route("/evento/{id}/editar", name="app_agenda_evento_editar", methods={"GET", "POST"})
     */
    public function editar(
        Request $request,
        EventoAgenda $evento,
        CursoRepository $cursoRepository,
        EntityManagerInterface $entityManager,
        ValidatorInterface $validator
    ): Response {
        $this->verificarInstituto($evento);

        return $this->formulario($request, $evento, $cursoRepository, $entityManager, $validator, false);
    }

    /**
     * @Route("/evento/{id}/eliminar", name="app_agenda_evento_eliminar", methods={"POST"})
     */
    public function eliminar(
        Request $request,
        EventoAgenda $evento,
        EntityManagerInterface $entityManager
    ): Response {
        $this->verificarInstituto($evento);

        if (!$this->isCsrfTokenValid('eliminar_evento_' . $evento->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'El formulario expiró. Volvé a intentarlo.');

            return $this->redirectToRoute('app_agenda_eventos_listado');
        }

        $titulo = $evento->getTitulo();
        $entityManager->remove($evento);
        $entityManager->flush();

        $this->addFlash('success', sprintf('Se eliminó "%s" de la agenda.', $titulo));

        return $this->redirectToRoute('app_agenda_eventos_listado');
    }

    /**
     * Listado de los eventos cargados, que es por donde se los edita y borra.
     *
     * @Route("/eventos/listado", name="app_agenda_eventos_listado", methods={"GET"})
     */
    public function listado(EventoAgendaRepository $eventoRepository, InstitutoTimezoneService $institutoTimezoneService): Response
    {
        $instituto = $this->getUser()->getInstituto();
        $hoy = $institutoTimezoneService->getNowForInstituto($instituto);

        return $this->render('agenda/eventos.html.twig', [
            'eventos' => $eventoRepository->findBy(['instituto' => $instituto], ['fechaInicio' => 'DESC']),
            'hoy' => $hoy,
        ]);
    }

    private function formulario(
        Request $request,
        EventoAgenda $evento,
        CursoRepository $cursoRepository,
        EntityManagerInterface $entityManager,
        ValidatorInterface $validator,
        bool $esNuevo
    ): Response {
        $instituto = $this->getUser()->getInstituto();

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('evento_agenda', (string) $request->request->get('_token'))) {
                $this->addFlash('danger', 'El formulario expiró. Volvé a intentarlo.');

                return $this->redirectToRoute('app_agenda_eventos_listado');
            }

            $evento->setTitulo(trim((string) $request->request->get('titulo')));
            $evento->setDescripcion(trim((string) $request->request->get('descripcion')) ?: null);
            $evento->setTipo((string) $request->request->get('tipo', EventoAgenda::TIPO_OTRO));
            $evento->setVisibilidad($request->request->get('visibilidad'));
            $evento->setTodoElDia(!$request->request->has('con_horario'));
            $evento->setCurso($this->cursoElegido($request, $cursoRepository, $instituto));

            $evento->setFechaInicio($this->fechaDelFormulario(
                $request->request->get('fecha_inicio'),
                $evento->isTodoElDia() ? null : $request->request->get('hora_inicio')
            ));

            $fechaFin = $this->fechaDelFormulario(
                $request->request->get('fecha_fin'),
                $evento->isTodoElDia() ? null : $request->request->get('hora_fin')
            );
            $evento->setFechaFin($fechaFin);

            if (!$evento->getFechaInicio()) {
                $this->addFlash('danger', 'La fecha de inicio es obligatoria.');
            } elseif ($fechaFin && $fechaFin < $evento->getFechaInicio()) {
                $this->addFlash('danger', 'La fecha de fin no puede ser anterior a la de inicio.');
            } else {
                $errores = $validator->validate($evento);

                if (count($errores) > 0) {
                    foreach ($errores as $error) {
                        $this->addFlash('danger', $error->getMessage());
                    }
                } else {
                    if ($esNuevo) {
                        $evento->setCreadoPor($this->getUser());
                        $entityManager->persist($evento);
                    }

                    $entityManager->flush();
                    $this->addFlash('success', $esNuevo
                        ? sprintf('Se agregó "%s" a la agenda.', $evento->getTitulo())
                        : 'Evento actualizado.');

                    return $this->redirectToRoute('app_agenda_eventos_listado');
                }
            }
        }

        return $this->render('agenda/evento_form.html.twig', [
            'evento' => $evento,
            'esNuevo' => $esNuevo,
            'cursos' => $cursoRepository->findByInstituto($instituto),
            'tipos' => EventoAgenda::TIPOS,
            'visibilidades' => EventoAgenda::VISIBILIDADES,
        ]);
    }

    private function cursoElegido(Request $request, CursoRepository $cursoRepository, $instituto): ?Curso
    {
        $cursoId = $request->request->get('curso');
        if (!$cursoId) {
            return null;
        }

        $curso = $cursoRepository->find((int) $cursoId);

        // Un curso de otro instituto se descarta en silencio: el desplegable solo ofrece los
        // propios, así que llegar acá con otro id es haber tocado el formulario a mano.
        return $curso && $curso->getInstituto() === $instituto ? $curso : null;
    }

    private function fechaDelFormulario($fecha, $hora): ?\DateTime
    {
        if (!is_string($fecha) || trim($fecha) === '') {
            return null;
        }

        $texto = trim($fecha) . ' ' . (is_string($hora) && trim($hora) !== '' ? trim($hora) : '00:00');

        try {
            return new \DateTime($texto);
        } catch (\Exception $e) {
            return null;
        }
    }

    private function verificarInstituto(EventoAgenda $evento): void
    {
        if ($evento->getInstituto() !== $this->getUser()->getInstituto()) {
            throw $this->createNotFoundException('Evento no encontrado');
        }
    }
}
