<?php

namespace App\Controller;

use App\Entity\BillingInvoice;
use App\Repository\BillingInvoiceRepository;
use App\Service\BillingService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\String\Slugger\SluggerInterface;

/**
 * @Route("/instituto/facturas")
 */
class BillingInvoiceController extends AbstractController
{
    private BillingService $billingService;
    private SluggerInterface $slugger;

    public function __construct(BillingService $billingService, SluggerInterface $slugger)
    {
        $this->billingService = $billingService;
        $this->slugger = $slugger;
    }

    /**
     * @Route("/", name="billing_invoice_index", methods={"GET"})
     */
    public function index(): Response
    {
        $instituto = $this->getUser()->getInstituto();
        
        if (!$instituto) {
            $this->addFlash('danger', 'No tienes un instituto asociado.');
            return $this->redirectToRoute('dashboard_index');
        }

        $pendingInvoices = $this->billingService->getPendingInvoices($instituto);
        $billingHistory = $this->billingService->getBillingHistory($instituto, 24);
        $billingStats = $this->billingService->getInstitutoBillingStats($instituto);
        $activeStudents = $this->billingService->getActiveStudentsCount($instituto);
        $estimatedNextMonth = $this->billingService->getEstimatedNextMonthCost($instituto);

        return $this->render('billing_invoice/index.html.twig', [
            'pendingInvoices' => $pendingInvoices,
            'billingHistory' => $billingHistory,
            'billingStats' => $billingStats,
            'instituto' => $instituto,
            'activeStudents' => $activeStudents,
            'estimatedNextMonth' => $estimatedNextMonth,
            'billingService' => $this->billingService,
        ]);
    }

    /**
     * @Route("/{id}", name="billing_invoice_show", methods={"GET"})
     */
    public function show(BillingInvoice $billingInvoice): Response
    {
        $instituto = $this->getUser()->getInstituto();
        
        // Verificar que la factura pertenece al instituto del usuario
        if ($billingInvoice->getInstituto() !== $instituto) {
            $this->addFlash('danger', 'No tienes permiso para ver esta factura.');
            return $this->redirectToRoute('billing_invoice_index');
        }

        return $this->render('billing_invoice/show.html.twig', [
            'invoice' => $billingInvoice,
            'instituto' => $instituto,
        ]);
    }

    /**
     * @Route("/{id}/pdf", name="billing_invoice_pdf", methods={"GET"})
     */
    public function pdf(BillingInvoice $billingInvoice): Response
    {
        $instituto = $this->getUser()->getInstituto();
        
        // Verificar que la factura pertenece al instituto del usuario
        if ($billingInvoice->getInstituto() !== $instituto) {
            $this->addFlash('danger', 'No tienes permiso para ver esta factura.');
            return $this->redirectToRoute('billing_invoice_index');
        }

        $html = $this->renderView('billing_invoice/pdf.html.twig', [
            'invoice' => $billingInvoice,
            'instituto' => $instituto,
        ]);

        $response = new Response($html);
        $response->headers->set('Content-Type', 'application/pdf');
        $response->headers->set('Content-Disposition', 'attachment; filename="factura-' . $billingInvoice->getId() . '.pdf"');

        return $response;
    }

    /**
     * @Route("/{id}/pay", name="billing_invoice_pay", methods={"POST"})
     */
    public function pay(Request $request, BillingInvoice $billingInvoice, EntityManagerInterface $entityManager): Response
    {
        $instituto = $this->getUser()->getInstituto();
        
        // Verificar que la factura pertenece al instituto del usuario
        if ($billingInvoice->getInstituto() !== $instituto) {
            $this->addFlash('danger', 'No tienes permiso para pagar esta factura.');
            return $this->redirectToRoute('billing_invoice_index');
        }

        // Verificar que la factura está pendiente o rechazada (para permitir reenvío)
        if (!$billingInvoice->isPending() && !$billingInvoice->isRejected()) {
            $this->addFlash('warning', 'Esta factura ya ha sido procesada.');
            return $this->redirectToRoute('billing_invoice_show', ['id' => $billingInvoice->getId()]);
        }

        // Verificar CSRF token
        if (!$this->isCsrfTokenValid('pay_invoice_' . $billingInvoice->getId(), $request->request->get('_token'))) {
            $this->addFlash('danger', 'Token de seguridad inválido.');
            return $this->redirectToRoute('billing_invoice_show', ['id' => $billingInvoice->getId()]);
        }

        // Verificar que se haya subido un comprobante
        $proofFile = $request->files->get('payment_proof');
        if (!$proofFile) {
            $this->addFlash('danger', 'Debes subir un comprobante de pago.');
            return $this->redirectToRoute('billing_invoice_show', ['id' => $billingInvoice->getId()]);
        }

        // Validar el archivo
        $allowedMimes = ['image/jpeg', 'image/png', 'image/jpg', 'application/pdf'];
        if (!in_array($proofFile->getMimeType(), $allowedMimes)) {
            $this->addFlash('danger', 'El archivo debe ser una imagen (JPG, PNG) o PDF.');
            return $this->redirectToRoute('billing_invoice_show', ['id' => $billingInvoice->getId()]);
        }

        // Validar tamaño (máximo 5MB)
        if ($proofFile->getSize() > 5 * 1024 * 1024) {
            $this->addFlash('danger', 'El archivo es demasiado grande. Máximo 5MB.');
            return $this->redirectToRoute('billing_invoice_show', ['id' => $billingInvoice->getId()]);
        }

        try {
            // Generar nombre único para el archivo
            $originalFilename = pathinfo($proofFile->getClientOriginalName(), PATHINFO_FILENAME);
            $safeFilename = $this->slugger->slug($originalFilename);
            $newFilename = 'invoice-' . $billingInvoice->getId() . '-' . $safeFilename . '-' . uniqid() . '.' . $proofFile->guessExtension();

            // Crear directorio si no existe
            $proofsDirectory = $this->getParameter('payment_proofs_directory');
            if (!is_dir($proofsDirectory)) {
                mkdir($proofsDirectory, 0755, true);
            }

            // Mover el archivo
            $proofFile->move($proofsDirectory, $newFilename);

            // Actualizar la factura con el comprobante y cambiar estado
            $billingInvoice->setPaymentProofPath($newFilename);
            $billingInvoice->setPaymentRequestedAt(new \DateTime());
            $billingInvoice->setStatus('pending_approval');
            // Limpiar datos de rechazo si existían
            $billingInvoice->setRejectionReason(null);
            $billingInvoice->setRejectedAt(null);
            $billingInvoice->setUpdatedAt(new \DateTime());

            $entityManager->flush();

            $this->addFlash('success', 'Comprobante de pago subido exitosamente. La factura está pendiente de aprobación por el administrador.');
            
        } catch (FileException $e) {
            $this->addFlash('danger', 'Error al subir el comprobante. Por favor, intenta nuevamente.');
        }
        
        return $this->redirectToRoute('billing_invoice_show', ['id' => $billingInvoice->getId()]);
    }
}
