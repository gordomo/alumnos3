<?php

namespace App\Controller;

use App\Entity\Alumno;
use App\Repository\AlumnoRepository;
use App\Repository\CalificacionRepository;
use App\Repository\ClaseDictadaRepository;
use App\Repository\MaterialCursoRepository;
use App\Repository\TareaEntregaRepository;
use App\Repository\TareaRepository;
use App\Service\DeudaCalculatorService;
use App\Service\InstitutoTimezoneService;
use App\Service\PromedioCalificacionService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

/**
 * La vista de la familia: lo del alumn@, de solo lectura, sin usuario ni contraseña.
 *
 * El tutor no tiene cuenta en el sistema. Lo que autoriza es el token de la URL, que llega en los
 * emails que ya recibe. Se eligió así en lugar de un rol con contraseña porque los padres no se
 * registran en sistemas, pero a un enlace le hacen clic; y porque no hay que darle de alta a cada
 * familia ni recuperarle contraseñas.
 *
 * Qué implica que sea pública:
 *
 * - Es de SOLO LECTURA. No hay ninguna acción, ningún formulario y ningún dato de otro alumn@.
 * - El token es de 32 bytes al azar y va en su propia columna única. Se puede regenerar desde la
 *   ficha del alumno, y eso invalida el anterior.
 * - Cada visita queda registrada (fecha y contador), así que el instituto puede ver si se usa.
 * - La regla de access_control para ^/familia/ está arriba de la de por defecto: si cayera en
 *   ^/ pediría login y el enlace no serviría.
 *
 * @Route("/familia")
 */
class FamiliaController extends AbstractController
{
    /**
     * @Route("/{token}", name="app_familia", methods={"GET"}, requirements={"token"="[A-Za-z0-9]{16,64}"})
     */
    public function ver(
        string $token,
        AlumnoRepository $alumnoRepository,
        CalificacionRepository $calificacionRepository,
        PromedioCalificacionService $promedioService,
        ClaseDictadaRepository $claseRepository,
        MaterialCursoRepository $materialRepository,
        TareaRepository $tareaRepository,
        TareaEntregaRepository $entregaRepository,
        DeudaCalculatorService $deudaCalculator,
        InstitutoTimezoneService $institutoTimezoneService,
        EntityManagerInterface $entityManager
    ): Response {
        $alumno = $alumnoRepository->findOneBy(['tokenTutor' => $token]);

        if (!$alumno) {
            // Sin decir si el token existió alguna vez: para quien lo prueba a mano, un enlace
            // vencido y uno inventado tienen que verse igual.
            return $this->render('familia/invalido.html.twig', [], new Response('', Response::HTTP_NOT_FOUND));
        }

        $instituto = $alumno->getInstituto();
        $hoy = $institutoTimezoneService->getNowForInstituto($instituto);

        $alumno->registrarAccesoTutor();
        $entityManager->flush();

        // Una fila por curso. Si el alumn@ se reinscribió al mismo curso hay más de un histórico:
        // se usa el activo, y si ninguno está activo el de alta más reciente. Tomar el primero
        // que apareciera mostraba el viejo, con el curso marcado como terminado y las notas y las
        // tareas de la inscripción anterior.
        $elegidos = [];
        foreach ($alumno->getCursosHistoricos() as $historico) {
            $curso = $historico->getCurso();
            if (!$curso) {
                continue;
            }

            $clave = $curso->getId();
            $actual = $elegidos[$clave] ?? null;

            if (!$actual
                || ($historico->isActivo() && !$actual->isActivo())
                || ($historico->isActivo() === $actual->isActivo()
                    && $historico->getFechaAlta() > $actual->getFechaAlta())
            ) {
                $elegidos[$clave] = $historico;
            }
        }

        $cursos = [];
        foreach ($elegidos as $clave => $historico) {
            $curso = $historico->getCurso();

            $tareas = $tareaRepository->findParaAlumno($curso);
            $entregadas = 0;
            foreach ($entregaRepository->findByHistorico($historico) as $entrega) {
                if ($entrega->isEntregada()) {
                    $entregadas++;
                }
            }

            $calificaciones = $calificacionRepository->findByHistorico($historico);

            $cursos[$clave] = [
                'curso' => $curso,
                'historico' => $historico,
                'activo' => $historico->isActivo(),
                'clases' => $claseRepository->findByCurso($curso, 10),
                'materiales' => $materialRepository->findByCurso($curso, true),
                'tareasPedidas' => count($tareas),
                'tareasEntregadas' => $entregadas,
                'resumenNotas' => $calificaciones ? $promedioService->resumir(
                    $calificaciones,
                    $instituto->getConfiguracion()
                        ? $instituto->getConfiguracion()->getCriterioAprobacionNotas()
                        : 'promedio'
                ) : null,
            ];
        }

        return $this->render('familia/ver.html.twig', [
            'alumno' => $alumno,
            'instituto' => $instituto,
            'cursos' => array_values($cursos),
            'asistencia' => $this->resumenAsistencia($alumno, $entityManager),
            'deudas' => $deudaCalculator->calcularDeudasAlumno($alumno),
            'hoy' => $hoy,
            'date_format' => $institutoTimezoneService->getDateFormatForInstituto($instituto),
        ]);
    }

    /**
     * Presentes y ausentes de cada curso, sin traer el detalle: acá alcanza el resumen.
     *
     * @return array<int, array{curso: string, presentes: int, ausentes: int, porcentaje: ?float}>
     */
    private function resumenAsistencia(Alumno $alumno, EntityManagerInterface $entityManager): array
    {
        $filas = $entityManager->createQueryBuilder()
            ->select('c.nombre AS curso', 'a.presente', 'COUNT(a.id) AS cantidad')
            ->from(\App\Entity\AsistenciaAlumnos::class, 'a')
            ->innerJoin('a.curso', 'c')
            ->andWhere('a.alumno = :alumno')
            ->setParameter('alumno', $alumno)
            ->groupBy('c.nombre')
            ->addGroupBy('a.presente')
            ->getQuery()
            ->getScalarResult();

        $porCurso = [];
        foreach ($filas as $fila) {
            $nombre = $fila['curso'];

            if (!isset($porCurso[$nombre])) {
                $porCurso[$nombre] = ['curso' => $nombre, 'presentes' => 0, 'ausentes' => 0, 'porcentaje' => null];
            }

            if ($fila['presente']) {
                $porCurso[$nombre]['presentes'] += (int) $fila['cantidad'];
            } else {
                $porCurso[$nombre]['ausentes'] += (int) $fila['cantidad'];
            }
        }

        foreach ($porCurso as $nombre => $datos) {
            $total = $datos['presentes'] + $datos['ausentes'];
            $porCurso[$nombre]['porcentaje'] = $total > 0 ? round(($datos['presentes'] / $total) * 100, 1) : null;
        }

        return array_values($porCurso);
    }
}
