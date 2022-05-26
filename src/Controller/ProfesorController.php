<?php

namespace App\Controller;

use App\Entity\AsistenciaProfesores;
use App\Entity\Profesor;
use App\Form\ProfesorType;
use App\Repository\AsistenciaProfesoresRepository;
use App\Repository\CursoRepository;
use App\Repository\ProfesorRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

/**
 * @Route("/admin/profesor")
 */
class ProfesorController extends AbstractController
{
    /**
     * @Route("/", name="app_profesor_index", methods={"GET"})
     */
    public function index(ProfesorRepository $profesorRepository): Response
    {
        return $this->render('profesor/index.html.twig', [
            'profesors' => $profesorRepository->findAll(),
        ]);
    }

    /**
     * @Route("/asistencias", name="asistencias", methods={"GET"})
     */
    public function asistencias(Request $request, CursoRepository $cursoRepository, ProfesorRepository $profesorRepository, AsistenciaProfesoresRepository $asistenciaProfesoresRepository): Response
    {

        $desde = $request->get('desde', date("Y/m/d"));

        $day = date('N', strtotime($desde));
        $firstday = date('Y/m/d', strtotime('-'.($day-1).' days', strtotime($desde)));
        $lastday = date('Y/m/d', strtotime('+'.(7-$day).' days', strtotime($desde)));

        $cursos = $cursoRepository->findAll();
        $profesores = $profesorRepository->findAll();

        $asistencias = $asistenciaProfesoresRepository->findAll();
        $asistenciasArray = [];

        foreach ($asistencias as $asistencia) {
            $asistenciasArray[$asistencia->getCurso()][$asistencia->getFecha()->format('Y/m/d')][$asistencia->getProfesor()->getId()] = array('presente' => $asistencia->getPresente(), 'reemplazante' => ($asistencia->getProfesorRemplazante()) ? $profesorRepository->find($asistencia->getProfesorRemplazante())->getApellido() : 'sin reemplazo');
        }

        return $this->render('profesor/asistencias.html.twig',[
            'cursos' => $cursos,
            'firstday' => $firstday,
            'todosLosProfes' => $profesores,
            'rango' => $this->createDateRangeArray($firstday, $lastday),
            'asistencias' => $asistenciasArray,
        ]);

    }

    /**
     * @Route("/informes", name="informes", methods={"GET"})
     */
    public function informes(Request $request, CursoRepository $cursoRepository, ProfesorRepository $profesorRepository, AsistenciaProfesoresRepository $asistenciaProfesoresRepository): Response
    {
        $ds = new \DateTime('first day of this month');
        $ls = new \DateTime('last day of this month');

        $desde = $request->get('desde', $ds->format("Y/m/d"));
        $hasta = $request->get('hasta', $ls->format("Y/m/d"));

        $cursos = $cursoRepository->findAll();
        $profesores = $profesorRepository->findAll();

        $asistencias = $asistenciaProfesoresRepository->findByFecha(new \DateTime($desde), new \DateTime($hasta));
        $asisArray = [];
        $reemplazantes = [];

        foreach ($asistencias as $asistencia) {
            $reemplazante = $asistencia->getProfesorRemplazante() ? $profesorRepository->find($asistencia->getProfesorRemplazante())->getApellido() : 'Sin Reemplazo';
            $asisArray[$asistencia->getProfesor()->getApellido()][$asistencia->getFecha()->format("Y/m/d")] = [
                'profesorRemplazante' => $reemplazante,
                'curso' => $cursoRepository->find($asistencia->getCurso())->getNombre(),
            ];
            if ($reemplazante !== 'Sin Reemplazo') {
                $reemplazantes[$asistencia->getFecha()->format("Y/m/d")] = $reemplazante;
            }
        }
        return $this->render('profesor/informes.html.twig',[
            'cursos' => $cursos,
            'todosLosProfes' => $profesores,
            'asistencias' => $asisArray,
            'desde' => $desde,
            'hasta' => $hasta,
            'rango' => $this->createDateRangeArray($desde, $hasta),
            'reemplazantes' => $reemplazantes
        ]);

    }

    /**
     * @Route("/new", name="app_profesor_new", methods={"GET", "POST"})
     */
    public function new(Request $request, ProfesorRepository $profesorRepository): Response
    {
        $profesor = new Profesor();
        $form = $this->createForm(ProfesorType::class, $profesor);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $profesorRepository->add($profesor);
            return $this->redirectToRoute('app_profesor_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->renderForm('profesor/new.html.twig', [
            'profesor' => $profesor,
            'form' => $form,
        ]);
    }

    /**
     * @Route("/{id}", name="app_profesor_show", methods={"GET"})
     */
    public function show(Profesor $profesor): Response
    {
        return $this->render('profesor/show.html.twig', [
            'profesor' => $profesor,
        ]);
    }

    /**
     * @Route("/{id}/edit", name="app_profesor_edit", methods={"GET", "POST"})
     */
    public function edit(Request $request, Profesor $profesor, ProfesorRepository $profesorRepository): Response
    {
        $form = $this->createForm(ProfesorType::class, $profesor);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $profesorRepository->add($profesor);
            return $this->redirectToRoute('app_profesor_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->renderForm('profesor/edit.html.twig', [
            'profesor' => $profesor,
            'form' => $form,
        ]);
    }

    /**
     * @Route("/{id}/falto/{remplazo}", name="falta_profe", methods={"GET", "POST"})
     */
    public function faltaprofe(Request $request, Profesor $profesor, ProfesorRepository $profesorRepository, $remplazo, AsistenciaProfesoresRepository $asistenciaProfesoresRepository): Response
    {

        $profeRemplazante = $profesorRepository->find($remplazo);

        $fecha = $request->get('fecha');
        $curso = $request->get('curso');

        $asistenciasGuarda = $asistenciaProfesoresRepository->findBy(['profesor' => $profesor, 'fecha' => new \DateTime($fecha)]);

        if (!empty($asistenciasGuarda[0])) {
            $asistenciasGuarda[0]->setProfesorRemplazante($profeRemplazante ? $profeRemplazante->getId() : 0);
            $asistenciaProfesoresRepository->add($asistenciasGuarda[0]);
        } else {
            $asistenciaNueva = new AsistenciaProfesores();
            $asistenciaNueva->setFecha(new \DateTime($fecha));
            $asistenciaNueva->setProfesor($profesor);
            $asistenciaNueva->setPresente(false);
            $asistenciaNueva->setCurso($curso);
            $asistenciaNueva->setProfesorRemplazante($profeRemplazante ? $profeRemplazante->getId() : 0);

            $asistenciaProfesoresRepository->add($asistenciaNueva);
        }

        return $this->redirectToRoute('asistencias', ['desde' => $fecha], Response::HTTP_SEE_OTHER);
    }

    /**
     * @Route("/{id}", name="app_profesor_delete", methods={"POST"})
     */
    public function delete(Request $request, Profesor $profesor, ProfesorRepository $profesorRepository): Response
    {
        if ($this->isCsrfTokenValid('delete'.$profesor->getId(), $request->request->get('_token'))) {
            $profesorRepository->remove($profesor);
        }

        return $this->redirectToRoute('app_profesor_index', [], Response::HTTP_SEE_OTHER);
    }

    function createDateRangeArray($strDateFrom,$strDateTo)
    {
        // takes two dates formatted as YYYY-MM-DD and creates an
        // inclusive array of the dates between the from and to dates.

        // could test validity of dates here but I'm already doing
        // that in the main script

        $aryRange = [];

        $iDateFrom = mktime(1, 0, 0, substr($strDateFrom, 5, 2), substr($strDateFrom, 8, 2), substr($strDateFrom, 0, 4));
        $iDateTo = mktime(1, 0, 0, substr($strDateTo, 5, 2), substr($strDateTo, 8, 2), substr($strDateTo, 0, 4));

        if ($iDateTo >= $iDateFrom) {
            array_push($aryRange, date('Y/m/d', $iDateFrom)); // first entry
            while ($iDateFrom<$iDateTo) {
                $iDateFrom += 86400; // add 24 hours
                array_push($aryRange, date('Y/m/d', $iDateFrom));
            }
        }
        return $aryRange;
    }
}
