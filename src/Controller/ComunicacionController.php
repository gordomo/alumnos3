<?php

namespace App\Controller;

use App\Entity\Alumno;
use App\Entity\Curso;
use App\Repository\AlumnoRepository;
use App\Repository\CursoRepository;
use App\Service\DeudaCalculatorService;
use App\Service\InstitutoTimezoneService;
use App\Service\NotificationService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Comunicaciones del instituto: mandar un aviso por email a varios alumnos de una vez.
 *
 * Existía EmailService::sendGeneralCommunication() sin ninguna pantalla que lo llamara, así que
 * el instituto no tenía forma de avisar nada. Apareció como necesidad concreta al subir el
 * precio de un curso: hay que poder avisarle a las familias antes de que les llegue el
 * recordatorio con el importe nuevo.
 *
 * El prefijo /instituto ya está cubierto por la regla de access_control que exige
 * ROLE_ADMIN_INSTITUTO, así que no hace falta agregar ninguna línea nueva ahí.
 *
 * @Route("/instituto/comunicaciones")
 */
class ComunicacionController extends AbstractController
{
    public const DESTINO_INSTITUTO = 'instituto';
    public const DESTINO_CURSO = 'curso';
    public const DESTINO_DEUDORES = 'deudores';

    public function __construct(
        private NotificationService $notificationService,
        private AlumnoRepository $alumnoRepository,
        private CursoRepository $cursoRepository,
        private DeudaCalculatorService $deudaCalculator,
        private InstitutoTimezoneService $institutoTimezoneService
    ) {
    }

    /**
     * @Route("", name="app_comunicacion_index", methods={"GET"})
     */
    public function index(Request $request): Response
    {
        $instituto = $this->getUser()->getInstituto();

        return $this->render('comunicacion/index.html.twig', [
            'cursos' => $this->cursoRepository->findBy(['instituto' => $instituto], ['nombre' => 'ASC']),
            'conteos' => $this->conteos($instituto),
            // Un atajo desde otra pantalla puede llegar con el mensaje pre-armado.
            'destinoPrevio' => $request->query->get('destino'),
            'cursoPrevio' => $request->query->get('curso'),
            'asuntoPrevio' => $request->query->get('asunto'),
            'mensajePrevio' => $request->query->get('mensaje'),
            'notificarA' => $instituto->getConfiguracion() ? $instituto->getConfiguracion()->getNotificarA() : 'alumno',
        ]);
    }

    /**
     * @Route("/enviar", name="app_comunicacion_enviar", methods={"POST"})
     */
    public function enviar(Request $request): Response
    {
        $instituto = $this->getUser()->getInstituto();

        if (!$this->isCsrfTokenValid('comunicacion_enviar', (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'Token de seguridad inválido.');

            return $this->redirectToRoute('app_comunicacion_index');
        }

        $asunto = trim((string) $request->request->get('asunto'));
        $mensaje = trim((string) $request->request->get('mensaje'));
        $destino = (string) $request->request->get('destino', self::DESTINO_INSTITUTO);
        $cursoId = $request->request->get('curso');

        if ($asunto === '' || $mensaje === '') {
            $this->addFlash('danger', 'El asunto y el mensaje son obligatorios.');

            return $this->redirectToRoute('app_comunicacion_index');
        }

        $curso = null;
        if ($destino === self::DESTINO_CURSO) {
            $curso = $cursoId !== null ? $this->cursoRepository->find((int) $cursoId) : null;

            if (!$curso || $curso->getInstituto() !== $instituto) {
                $this->addFlash('danger', 'Elegí un curso del instituto.');

                return $this->redirectToRoute('app_comunicacion_index');
            }
        }

        $alumnos = $this->destinatarios($instituto, $destino, $curso);

        if (!$alumnos) {
            $this->addFlash('warning', 'No hay alumn@s que cumplan ese criterio, así que no se envió nada.');

            return $this->redirectToRoute('app_comunicacion_index');
        }

        $enviados = 0;
        $fallidos = 0;
        foreach ($alumnos as $alumno) {
            if ($this->notificationService->enviarComunicacion($alumno, $asunto, $mensaje, $this->getUser())) {
                $enviados++;
            } else {
                $fallidos++;
            }
        }

        $this->addFlash('success', sprintf(
            'Comunicación enviada a %d alumn@(s).%s Podés ver cada envío en el Historial de Emails.',
            $enviados,
            $fallidos > 0
                ? sprintf(' %d no se pudo(ieron) enviar, normalmente por falta de email cargado.', $fallidos)
                : ''
        ));

        return $this->redirectToRoute('app_comunicacion_index');
    }

    /**
     * Cuántos alumnos caen en cada criterio, para mostrarlo antes de enviar.
     *
     * @return array<string, int>
     */
    private function conteos($instituto): array
    {
        return [
            self::DESTINO_INSTITUTO => count($this->destinatarios($instituto, self::DESTINO_INSTITUTO)),
            self::DESTINO_DEUDORES => count($this->destinatarios($instituto, self::DESTINO_DEUDORES)),
        ];
    }

    /**
     * Alumnos que reciben la comunicación según el criterio elegido.
     *
     * Siempre alumnos activos: a los dados de baja no se les manda un aviso del instituto.
     *
     * @return Alumno[]
     */
    private function destinatarios($instituto, string $destino, ?Curso $curso = null): array
    {
        $activos = $this->alumnoRepository->findBy(['instituto' => $instituto, 'activo' => true]);

        if ($destino === self::DESTINO_CURSO && $curso) {
            return array_values(array_filter($activos, static function (Alumno $alumno) use ($curso) {
                foreach ($alumno->getCursosHistoricos() as $historico) {
                    if ($historico->isActivo() && $historico->getCurso() === $curso) {
                        return true;
                    }
                }

                return false;
            }));
        }

        if ($destino === self::DESTINO_DEUDORES) {
            // Vencidas de verdad, con el mismo criterio que la pantalla de deudas: mes anterior,
            // o mes actual con el primer vencimiento ya cumplido.
            $hoy = $this->institutoTimezoneService->getNowForInstituto($instituto);
            $mes = (int) $hoy->format('n');
            $ano = (int) $hoy->format('Y');
            $dia = (int) $hoy->format('d');

            $vencimientos = $instituto->getVencimientos()->toArray();
            usort($vencimientos, static fn($a, $b) => $a->getOrden() <=> $b->getOrden());
            $diaVencimiento = $vencimientos ? (int) reset($vencimientos)->getDiaVencimiento() : 5;

            $porAlumno = $this->deudaCalculator->calcularDeudasParaAlumnos($activos);

            return array_values(array_filter($activos, static function (Alumno $alumno) use ($porAlumno, $mes, $ano, $dia, $diaVencimiento) {
                foreach ($porAlumno[$alumno->getId()] ?? [] as $deuda) {
                    $deudaAno = (int) $deuda['ano'];
                    $deudaMes = (int) $deuda['mes'];

                    $esMesAnterior = $deudaAno < $ano || ($deudaAno === $ano && $deudaMes < $mes);
                    $esMesActualVencido = $deudaAno === $ano && $deudaMes === $mes && $dia >= $diaVencimiento;

                    if ($esMesAnterior || $esMesActualVencido) {
                        return true;
                    }
                }

                return false;
            }));
        }

        return $activos;
    }
}
