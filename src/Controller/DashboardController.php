<?php

namespace App\Controller;

use App\Repository\AlumnoRepository;
use App\Repository\AlumnosPagosRepository;
use App\Repository\CursoRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class DashboardController extends AbstractController
{
    /**
     * @Route("/dashboard", name="dashboard_index", methods={"GET"})
     */
    public function index(Request $request, AlumnosPagosRepository $alumnosPagosRepository, AlumnoRepository $alumnoRepository, CursoRepository $cursoRepository): Response
    {
        $busqueda = $request->get('busqueda', '');
        $action = $request->get('action', '');
        $alumnosIds = [];
        $deudores = [];

        if($busqueda) {
            $alumnos = $alumnoRepository->findByApellido($busqueda, 0, 0, 'todos');
            foreach ($alumnos as $alumno) {
                $alumnosIds[] = $alumno->getId();
            }
        } else {
            $alumnos = $alumnoRepository->findBy(['activo' => 1]);
        }

        foreach ($alumnos as $alumno) {
            if($alumno->getDebeMes()) {
                $deudores[] = $alumno;
            }
        }

        $alumnos_pagos = $alumnosPagosRepository->findLast50($alumnosIds);

        $cursos = $cursoRepository->findCursosConAlumnos($alumnos);

        return $this->render('dashboard/index.html.twig', [
            'alumnos_pagos' => $alumnos_pagos,
            'busqueda' => $busqueda,
            'action' => $action,
            'deudores' => $deudores,
            'cursos' => $cursos,
        ]);

    }
}