<?php

namespace App\Controller;

use App\Entity\AlumnoCursoHistorico;
use App\Repository\AlumnoCursoHistoricoRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

/**
 * @Route("/pagos-historicos")
 */
class PagosHistoricosController extends AbstractController
{
    /**
     * @Route("/", name="app_pagos_historicos_index", methods={"GET"})
     */
    public function index(AlumnoCursoHistoricoRepository $historicoRepository): Response
    {
        $historicos = $historicoRepository->findAll();
        
        // Agrupar por alumno
        $pagosPorAlumno = [];
        foreach ($historicos as $historico) {
            $alumno = $historico->getAlumno();
            $alumnoId = $alumno->getId();
            
            if (!isset($pagosPorAlumno[$alumnoId])) {
                $pagosPorAlumno[$alumnoId] = [
                    'alumno' => $alumno,
                    'cursos' => []
                ];
            }
            
            $pagosPorAlumno[$alumnoId]['cursos'][] = [
                'curso' => $historico->getCurso(),
                'fecha_inicio' => $historico->getFechaInicio(),
                'fecha_fin' => $historico->getFechaFin(),
                'precio' => $historico->getPrecio(),
                'meses_pagados' => $historico->getMesesPagados(),
                'meses_adeudados' => $historico->getMesesAdeudados(),
                'estado' => $historico->getEstado()
            ];
        }

        return $this->render('pagos_historicos/index.html.twig', [
            'pagos_por_alumno' => $pagosPorAlumno
        ]);
    }

    /**
     * @Route("/alumno/{id}", name="app_pagos_historicos_alumno", methods={"GET"})
     */
    public function alumno(AlumnoCursoHistoricoRepository $historicoRepository, int $id): Response
    {
        $qb = $historicoRepository->createQueryBuilder('h')
            ->leftJoin('h.curso', 'c')
            ->leftJoin('h.alumno', 'a')
            ->where('a.id = :alumnoId')
            ->setParameter('alumnoId', $id)
            ->orderBy('h.fechaInicio', 'DESC');

        $historicos = $qb->getQuery()->getResult();
        
        return $this->render('pagos_historicos/alumno.html.twig', [
            'historicos' => $historicos
        ]);
    }
} 