<?php

namespace App\Controller;

use App\Entity\BillingInvoice;
use App\Repository\BillingInvoiceRepository;
use App\Repository\InstitutoRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

/**
 * @Route("/admin/facturas")
 */
class AdminBillingInvoiceController extends AbstractController
{
    private InstitutoRepository $institutoRepository;

    public function __construct(InstitutoRepository $institutoRepository)
    {
        $this->institutoRepository = $institutoRepository;
    }

    /**
     * Verifica que el usuario tenga permisos de administrador
     */
    private function checkAdminAccess(): ?Response
    {
        $usuarioActual = $this->getUser();
        
        if (!$usuarioActual || (!in_array('ROLE_SUPER_ADMIN', $usuarioActual->getRoles()) && !in_array('ROLE_ADMIN', $usuarioActual->getRoles()))) {
            $this->addFlash('danger', 'No tienes permisos para acceder a esta sección.');
            return $this->redirectToRoute('admin_instituto_index');
        }
        
        return null;
    }

    /**
     * @Route("/", name="admin_billing_invoice_index", methods={"GET"})
     */
    public function index(BillingInvoiceRepository $billingInvoiceRepository, Request $request): Response
    {
        if ($response = $this->checkAdminAccess()) {
            return $response;
        }

        $usuarioActual = $this->getUser();
        $institutoId = $request->query->get('instituto');
        $status = $request->query->get('status', 'all');

        // Obtener institutos según el rol
        $institutos = $this->institutoRepository->findAllForUser($usuarioActual);

        // Obtener facturas según filtros
        $qb = $billingInvoiceRepository->createQueryBuilder('bi');

        // Si es ADMIN (no SUPER_ADMIN), filtrar por sus institutos
        if (!in_array('ROLE_SUPER_ADMIN', $usuarioActual->getRoles())) {
            $institutosIds = array_map(function($inst) { return $inst->getId(); }, $institutos);
            if (!empty($institutosIds)) {
                $qb->andWhere('bi.instituto IN (:institutos)')
                   ->setParameter('institutos', $institutosIds);
            } else {
                // Si no tiene institutos asignados, no mostrar nada
                $qb->andWhere('1 = 0');
            }
        }

        // Filtrar por instituto si se especifica
        if ($institutoId) {
            $qb->andWhere('bi.instituto = :institutoId')
               ->setParameter('institutoId', $institutoId);
        }

        // Filtrar por estado
        if ($status === 'pending_approval') {
            $qb->andWhere('bi.status = :status')
               ->setParameter('status', 'pending_approval');
        } elseif ($status === 'all') {
            // Mostrar todas
        } else {
            $qb->andWhere('bi.status = :status')
               ->setParameter('status', $status);
        }

        $qb->orderBy('bi.createdAt', 'DESC');

        $invoices = $qb->getQuery()->getResult();

        // Estadísticas (filtrar por institutos si es ADMIN)
        $statsQb = $billingInvoiceRepository->createQueryBuilder('bi');
        if (!in_array('ROLE_SUPER_ADMIN', $usuarioActual->getRoles())) {
            $institutosIds = array_map(function($inst) { return $inst->getId(); }, $institutos);
            if (!empty($institutosIds)) {
                $statsQb->andWhere('bi.instituto IN (:institutos)')
                        ->setParameter('institutos', $institutosIds);
            } else {
                $statsQb->andWhere('1 = 0');
            }
        }
        
        // Construir las estadísticas correctamente
        $baseQb = clone $statsQb;
        $stats = [
            'pending_approval' => (int)(clone $baseQb)->andWhere('bi.status = :status')->setParameter('status', 'pending_approval')->select('COUNT(bi.id)')->getQuery()->getSingleScalarResult(),
            'paid' => (int)(clone $baseQb)->andWhere('bi.status = :status')->setParameter('status', 'paid')->select('COUNT(bi.id)')->getQuery()->getSingleScalarResult(),
            'rejected' => (int)(clone $baseQb)->andWhere('bi.status = :status')->setParameter('status', 'rejected')->select('COUNT(bi.id)')->getQuery()->getSingleScalarResult(),
            'pending' => (int)(clone $baseQb)->andWhere('bi.status = :status')->setParameter('status', 'pending')->select('COUNT(bi.id)')->getQuery()->getSingleScalarResult(),
            // Vencidas: pendientes con la fecha de vencimiento pasada. Son las que hay que reclamar.
            'vencidas' => (int)(clone $baseQb)
                ->andWhere('bi.status = :status')->setParameter('status', 'pending')
                ->andWhere('bi.dueDate IS NOT NULL AND bi.dueDate < :hoy')
                ->setParameter('hoy', new \DateTime('today'))
                ->select('COUNT(bi.id)')->getQuery()->getSingleScalarResult(),
            // Lo que falta entrar: incluye las que están esperando que revisemos un comprobante.
            'a_cobrar' => (float)(clone $baseQb)
                ->andWhere('bi.status IN (:estados)')
                ->setParameter('estados', ['pending', 'pending_approval'])
                ->select('COALESCE(SUM(bi.totalAmount), 0)')->getQuery()->getSingleScalarResult(),
        ];

        return $this->render('admin/billing_invoice/index.html.twig', [
            'invoices' => $invoices,
            'institutos' => $institutos,
            'selectedInstituto' => $institutoId,
            'selectedStatus' => $status,
            'stats' => $stats,
        ]);
    }

    /**
     * @Route("/{id}", name="admin_billing_invoice_show", methods={"GET"})
     */
    public function show(BillingInvoice $billingInvoice): Response
    {
        if ($response = $this->checkAdminAccess()) {
            return $response;
        }

        $usuarioActual = $this->getUser();
        
        // Verificar acceso al instituto
        if (!in_array('ROLE_SUPER_ADMIN', $usuarioActual->getRoles())) {
            $institutos = $this->institutoRepository->findAllForUser($usuarioActual);
            $institutosIds = array_map(function($inst) { return $inst->getId(); }, $institutos);
            
            if (!in_array($billingInvoice->getInstituto()->getId(), $institutosIds)) {
                $this->addFlash('danger', 'No tienes permiso para ver esta factura.');
                return $this->redirectToRoute('admin_billing_invoice_index');
            }
        }

        return $this->render('admin/billing_invoice/show.html.twig', [
            'invoice' => $billingInvoice,
        ]);
    }

    /**
     * @Route("/{id}/approve", name="admin_billing_invoice_approve", methods={"POST"})
     */
    public function approve(Request $request, BillingInvoice $billingInvoice, EntityManagerInterface $entityManager): Response
    {
        if ($response = $this->checkAdminAccess()) {
            return $response;
        }

        $usuarioActual = $this->getUser();
        
        // Verificar acceso al instituto
        if (!in_array('ROLE_SUPER_ADMIN', $usuarioActual->getRoles())) {
            $institutos = $this->institutoRepository->findAllForUser($usuarioActual);
            $institutosIds = array_map(function($inst) { return $inst->getId(); }, $institutos);
            
            if (!in_array($billingInvoice->getInstituto()->getId(), $institutosIds)) {
                $this->addFlash('danger', 'No tienes permiso para aprobar esta factura.');
                return $this->redirectToRoute('admin_billing_invoice_index');
            }
        }

        // Verificar que la factura está pendiente de aprobación
        if (!$billingInvoice->isPendingApproval()) {
            $this->addFlash('warning', 'Esta factura no está pendiente de aprobación.');
            return $this->redirectToRoute('admin_billing_invoice_show', ['id' => $billingInvoice->getId()]);
        }

        // Verificar CSRF token
        if (!$this->isCsrfTokenValid('approve_invoice_' . $billingInvoice->getId(), $request->request->get('_token'))) {
            $this->addFlash('danger', 'Token de seguridad inválido.');
            return $this->redirectToRoute('admin_billing_invoice_show', ['id' => $billingInvoice->getId()]);
        }

        // Aprobar el pago
        $billingInvoice->setStatus('paid');
        $billingInvoice->setPaidAt(new \DateTime());
        $billingInvoice->setApprovedBy($usuarioActual);
        $billingInvoice->setApprovedAt(new \DateTime());
        $billingInvoice->setUpdatedAt(new \DateTime());

        $entityManager->flush();

        $this->addFlash('success', 'Factura aprobada exitosamente.');
        
        return $this->redirectToRoute('admin_billing_invoice_show', ['id' => $billingInvoice->getId()]);
    }

    /**
     * @Route("/{id}/reject", name="admin_billing_invoice_reject", methods={"POST"})
     */
    public function reject(Request $request, BillingInvoice $billingInvoice, EntityManagerInterface $entityManager): Response
    {
        if ($response = $this->checkAdminAccess()) {
            return $response;
        }

        $usuarioActual = $this->getUser();
        
        // Verificar acceso al instituto
        if (!in_array('ROLE_SUPER_ADMIN', $usuarioActual->getRoles())) {
            $institutos = $this->institutoRepository->findAllForUser($usuarioActual);
            $institutosIds = array_map(function($inst) { return $inst->getId(); }, $institutos);
            
            if (!in_array($billingInvoice->getInstituto()->getId(), $institutosIds)) {
                $this->addFlash('danger', 'No tienes permiso para rechazar esta factura.');
                return $this->redirectToRoute('admin_billing_invoice_index');
            }
        }

        // Verificar que la factura está pendiente de aprobación
        if (!$billingInvoice->isPendingApproval()) {
            $this->addFlash('warning', 'Esta factura no está pendiente de aprobación.');
            return $this->redirectToRoute('admin_billing_invoice_show', ['id' => $billingInvoice->getId()]);
        }

        // Verificar CSRF token
        if (!$this->isCsrfTokenValid('reject_invoice_' . $billingInvoice->getId(), $request->request->get('_token'))) {
            $this->addFlash('danger', 'Token de seguridad inválido.');
            return $this->redirectToRoute('admin_billing_invoice_show', ['id' => $billingInvoice->getId()]);
        }

        $rejectionReason = $request->request->get('rejection_reason', '');

        if (empty($rejectionReason)) {
            $this->addFlash('danger', 'Debes proporcionar un motivo de rechazo.');
            return $this->redirectToRoute('admin_billing_invoice_show', ['id' => $billingInvoice->getId()]);
        }

        // Rechazar el pago
        $billingInvoice->setStatus('rejected');
        $billingInvoice->setRejectionReason($rejectionReason);
        $billingInvoice->setRejectedAt(new \DateTime());
        $billingInvoice->setUpdatedAt(new \DateTime());

        $entityManager->flush();

        $this->addFlash('success', 'Factura rechazada exitosamente.');
        
        return $this->redirectToRoute('admin_billing_invoice_show', ['id' => $billingInvoice->getId()]);
    }
}
