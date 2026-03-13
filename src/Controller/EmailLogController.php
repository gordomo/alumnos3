<?php

namespace App\Controller;

use App\Entity\EmailLog;
use App\Entity\Alumno;
use App\Entity\DeudaAlumno;
use App\Repository\EmailLogRepository;
use App\Repository\AlumnoRepository;
use App\Repository\DeudaAlumnoRepository;
use App\Service\NotificationService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Knp\Component\Pager\PaginatorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * @Route("/instituto/emails")
 */
#[IsGranted('ROLE_ADMIN_INSTITUTO')]
class EmailLogController extends AbstractController
{
    /**
     * @Route("/", name="app_email_log_index", methods={"GET"})
     */
    public function index(EmailLogRepository $emailLogRepository, Request $request, PaginatorInterface $paginator): Response
    {
        $instituto = $this->getUser()->getInstituto();
        
        if (!$instituto) {
            $this->addFlash('danger', 'No tienes un instituto asignado.');
            return $this->redirectToRoute('app_login');
        }
        
        $tipo = $request->query->get('tipo');
        $estado = $request->query->get('estado');
        
        $queryBuilder = $emailLogRepository->createQueryBuilder('e')
            ->andWhere('e.instituto = :instituto')
            ->setParameter('instituto', $instituto)
            ->orderBy('e.fechaEnvio', 'DESC');
        
        if ($tipo) {
            $queryBuilder->andWhere('e.tipo = :tipo')
                ->setParameter('tipo', $tipo);
        }
        
        if ($estado) {
            $queryBuilder->andWhere('e.estado = :estado')
                ->setParameter('estado', $estado);
        }
        
        $emails = $paginator->paginate(
            $queryBuilder,
            $request->query->getInt('page', 1),
            20
        );
        
        $totalEnviados = $emailLogRepository->countByEstado($instituto, 'enviado');
        $totalFallidos = $emailLogRepository->countByEstado($instituto, 'fallido');
        
        return $this->render('email_log/index.html.twig', [
            'emails' => $emails,
            'totalEnviados' => $totalEnviados,
            'totalFallidos' => $totalFallidos,
            'filtroTipo' => $tipo,
            'filtroEstado' => $estado,
        ]);
    }

    /**
     * @Route("/{id}", name="app_email_log_show", methods={"GET"})
     */
    public function show(EmailLog $emailLog): Response
    {
        $instituto = $this->getUser()->getInstituto();
        
        if ($emailLog->getInstituto() !== $instituto) {
            $this->addFlash('danger', 'No tienes permiso para ver este email.');
            return $this->redirectToRoute('app_email_log_index');
        }
        
        return $this->render('email_log/show.html.twig', [
            'email' => $emailLog,
        ]);
    }

    /**
     * @Route("/alumno/{id}", name="app_email_log_alumno", methods={"GET"})
     */
    public function emailsAlumno(Alumno $alumno, EmailLogRepository $emailLogRepository): Response
    {
        $instituto = $this->getUser()->getInstituto();
        
        if ($alumno->getInstituto() !== $instituto) {
            $this->addFlash('danger', 'No tienes permiso para ver los emails de este alumno.');
            return $this->redirectToRoute('app_alumno_index');
        }
        
        $emails = $emailLogRepository->findByAlumno($alumno);
        
        return $this->render('email_log/alumno.html.twig', [
            'alumno' => $alumno,
            'emails' => $emails,
        ]);
    }

    /**
     * @Route("/enviar-recordatorio/{id}", name="app_email_send_recordatorio", methods={"POST"})
     */
    public function enviarRecordatorio(
        Alumno $alumno,
        DeudaAlumnoRepository $deudaRepository,
        NotificationService $notificationService,
        EmailLogRepository $emailLogRepository,
        Request $request,
        \App\Service\DeudaCalculatorService $deudaCalculator,
        \Doctrine\ORM\EntityManagerInterface $entityManager
    ): Response {
        $instituto = $this->getUser()->getInstituto();
        
        if ($alumno->getInstituto() !== $instituto) {
            $this->addFlash('danger', 'No tienes permiso para enviar emails a este alumno.');
            return $this->redirectToRoute('app_alumno_index');
        }
        
        if (!$this->isCsrfTokenValid('send_recordatorio'.$alumno->getId(), $request->request->get('_token'))) {
            $this->addFlash('danger', 'Token CSRF inválido.');
            return $this->redirectToRoute('app_alumno_show', ['id' => $alumno->getId()]);
        }
        
        // Verificar si se puede enviar (no se envió en las últimas 48 horas)
        if (!$emailLogRepository->puedeEnviarRecordatorio($alumno, 48)) {
            $ultimoRecordatorio = $emailLogRepository->findUltimoRecordatorio($alumno);
            $ahora = new \DateTime();
            $diferencia = $ahora->diff($ultimoRecordatorio->getFechaEnvio());
            $horasDesdeUltimo = ($diferencia->days * 24) + $diferencia->h;
            $horasRestantes = 48 - $horasDesdeUltimo;
            
            $this->addFlash('warning', "Debes esperar {$horasRestantes} horas más para enviar otro recordatorio a este alumno. Último envío: " . $ultimoRecordatorio->getFechaEnvio()->format('d/m/Y H:i'));
            return $this->redirectToRoute('app_alumno_show', ['id' => $alumno->getId()]);
        }
        
        // Sincronizar deudas calculadas on-demand con la tabla
        $deudaCalculator->sincronizarDeudasConTabla($alumno);
        $entityManager->refresh($alumno);
        
        // Obtener deudas pendientes del alumno
        $deudas = $deudaRepository->createQueryBuilder('d')
            ->leftJoin('d.aplicaciones', 'pa')
            ->groupBy('d.id')
            ->having('COALESCE(SUM(pa.montoAplicado), 0) < d.monto + COALESCE(d.interes, 0)')
            ->andWhere('d.alumno = :alumno')
            ->setParameter('alumno', $alumno)
            ->orderBy('d.ano', 'ASC')
            ->addOrderBy('d.mes', 'ASC')
            ->getQuery()
            ->getResult();
        
        if (empty($deudas)) {
            $this->addFlash('warning', 'El alumno no tiene deudas pendientes.');
            return $this->redirectToRoute('app_alumno_show', ['id' => $alumno->getId()]);
        }
        
        $enviados = 0;
        $errores = [];
        
        foreach ($deudas as $deuda) {
            try {
                if ($notificationService->enviarRecordatorioDeuda($alumno, $deuda, null, true, $this->getUser())) {
                    $enviados++;
                }
            } catch (\Exception $e) {
                $errores[] = $e->getMessage();
            }
        }
        
        if ($enviados > 0) {
            $this->addFlash('success', "Se enviaron {$enviados} recordatorio(s) de deuda a {$alumno->getNombreApellido()}.");
        }
        
        if (!empty($errores)) {
            foreach ($errores as $error) {
                $this->addFlash('danger', 'Error: ' . $error);
            }
        }
        
        return $this->redirectToRoute('app_alumno_show', ['id' => $alumno->getId()]);
    }

    /**
     * @Route("/reenviar-recibo/{id}", name="app_email_reenviar_recibo", methods={"POST"})
     */
    public function reenviarRecibo(
        EmailLog $emailLog,
        NotificationService $notificationService,
        Request $request
    ): Response {
        $instituto = $this->getUser()->getInstituto();
        
        if ($emailLog->getInstituto() !== $instituto) {
            $this->addFlash('danger', 'No tienes permiso para reenviar este email.');
            return $this->redirectToRoute('app_email_log_index');
        }
        
        if (!$emailLog->getPago()) {
            $this->addFlash('danger', 'Este email no está asociado a un pago.');
            return $this->redirectToRoute('app_email_log_show', ['id' => $emailLog->getId()]);
        }
        
        if (!$this->isCsrfTokenValid('reenviar_recibo'.$emailLog->getId(), $request->request->get('_token'))) {
            $this->addFlash('danger', 'Token CSRF inválido.');
            return $this->redirectToRoute('app_email_log_show', ['id' => $emailLog->getId()]);
        }
        
        try {
            $pago = $emailLog->getPago();
            $alumno = $pago->getAlumno();
            
            if ($notificationService->enviarReciboPago($pago, $emailLog->getDestinatario(), true, $this->getUser())) {
                $this->addFlash('success', "Recibo reenviado exitosamente a {$emailLog->getDestinatario()}.");
            } else {
                $this->addFlash('warning', 'No se pudo reenviar el recibo. Verifica la configuración.');
            }
        } catch (\Exception $e) {
            $this->addFlash('danger', 'Error al reenviar el recibo: ' . $e->getMessage());
        }
        
        return $this->redirectToRoute('app_email_log_show', ['id' => $emailLog->getId()]);
    }
}
