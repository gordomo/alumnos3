<?php

namespace App\Service;

use App\Entity\Instituto;
use App\Entity\Alumno;
use App\Entity\AlumnosPagos;
use App\Entity\DeudaAlumno;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mime\Address;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use App\Service\TokenService;

class NotificationService
{
    private MailerInterface $mailer;
    private EntityManagerInterface $entityManager;
    private UrlGeneratorInterface $urlGenerator;
    private TokenService $tokenService;
    private string $baseUrl;

    public function __construct(
        MailerInterface $mailer,
        EntityManagerInterface $entityManager,
        UrlGeneratorInterface $urlGenerator,
        TokenService $tokenService
    ) {
        $this->mailer = $mailer;
        $this->entityManager = $entityManager;
        $this->urlGenerator = $urlGenerator;
        $this->tokenService = $tokenService;
        
        // Obtener URL base
        try {
            $this->baseUrl = rtrim($this->urlGenerator->generate('app_alumnos_pagos_index', [], UrlGeneratorInterface::ABSOLUTE_URL), '/');
            // Remover la ruta específica para obtener solo el dominio
            $this->baseUrl = preg_replace('/\/alumnos\/pagos.*$/', '', $this->baseUrl);
        } catch (\Exception $e) {
            $this->baseUrl = 'http://localhost:8080';
        }
    }

    /**
     * Envía un email con el recibo/factura de un pago
     */
    public function enviarReciboPago(AlumnosPagos $pago, ?string $emailDestino = null, bool $forzarEnvio = false): bool
    {
        $instituto = $pago->getAlumno()->getInstituto();
        $configuracion = $instituto->getConfiguracion();
        
        // Verificar si está habilitado el envío de facturas/recibos (a menos que se fuerce)
        if (!$forzarEnvio && (!$configuracion || !$configuracion->getEnviarFacturasRecibos())) {
            return false;
        }
        
        $alumno = $pago->getAlumno();
        $emailAlumno = $emailDestino ?? $alumno->getEmail();
        
        if (!$emailAlumno) {
            return false;
        }
        
        // Usar email del instituto si está configurado y es del dominio correcto
        // Si no, usar un email por defecto del dominio del servidor
        $institutoEmail = $instituto->getEmail();
        $fromEmail = 'noreply@teambuilder.com.ar'; // Email por defecto del dominio del servidor
        
        // Si el email del instituto está configurado y es válido, usarlo
        if ($institutoEmail && filter_var($institutoEmail, FILTER_VALIDATE_EMAIL)) {
            // Verificar si el email es del dominio del servidor o un dominio válido
            $emailDomain = substr(strrchr($institutoEmail, "@"), 1);
            if (in_array($emailDomain, ['teambuilder.com.ar', 'dattaweb.com'])) {
                $fromEmail = $institutoEmail;
            }
        }
        
        $fromName = $instituto->getNombre() ?? 'Instituto';
        
        try {
            $logoUrl = $this->getLogoUrl($instituto);
            
            $email = (new TemplatedEmail())
                ->from(new Address($fromEmail, $fromName))
                ->to($emailAlumno)
                ->subject('Recibo de Pago - ' . $instituto->getNombre())
                ->htmlTemplate('emails/recibo_pago.html.twig')
                ->context([
                    'instituto' => $instituto,
                    'pago' => $pago,
                    'alumno' => $alumno,
                    'logoUrl' => $logoUrl,
                    'textoPersonalizado' => $configuracion ? $configuracion->getTextoPersonalizadoEmail() : null,
                ]);
            
            $this->mailer->send($email);
            
            // Consumir tokens por enviar notificación
            $this->tokenService->consumeTokens(
                $instituto,
                'notificacion.send',
                null,
                'Enviar recibo de pago a ' . $alumno->getNombreApellido(),
                'AlumnosPagos',
                $pago->getId()
            );
            
            return true;
        } catch (\Exception $e) {
            // Log error si es necesario
            throw new \RuntimeException(
                sprintf(
                    'Error al enviar email de recibo: %s (From: %s, To: %s)',
                    $e->getMessage(),
                    $fromEmail,
                    $emailAlumno
                ),
                0,
                $e
            );
        }
    }

    /**
     * Envía un recordatorio de deuda pendiente
     */
    public function enviarRecordatorioDeuda(Alumno $alumno, DeudaAlumno $deuda, ?string $emailDestino = null, bool $forzarEnvio = false): bool
    {
        $instituto = $alumno->getInstituto();
        $configuracion = $instituto->getConfiguracion();
        
        // Verificar si está habilitado el envío de recordatorios (a menos que se fuerce)
        if (!$forzarEnvio && (!$configuracion || !$configuracion->getEnviarRecordatoriosDeudas())) {
            return false;
        }
        
        $emailAlumno = $emailDestino ?? $alumno->getEmail();
        
        if (!$emailAlumno) {
            return false;
        }
        
        // Usar email del instituto si está configurado y es del dominio correcto
        // Si no, usar un email por defecto del dominio del servidor
        $institutoEmail = $instituto->getEmail();
        $fromEmail = 'noreply@teambuilder.com.ar'; // Email por defecto del dominio del servidor
        
        // Si el email del instituto está configurado y es válido, usarlo
        if ($institutoEmail && filter_var($institutoEmail, FILTER_VALIDATE_EMAIL)) {
            // Verificar si el email es del dominio del servidor o un dominio válido
            $emailDomain = substr(strrchr($institutoEmail, "@"), 1);
            if (in_array($emailDomain, ['teambuilder.com.ar', 'dattaweb.com'])) {
                $fromEmail = $institutoEmail;
            }
        }
        
        $fromName = $instituto->getNombre() ?? 'Instituto';
        
        try {
            $logoUrl = $this->getLogoUrl($instituto);
            
            $email = (new TemplatedEmail())
                ->from(new Address($fromEmail, $fromName))
                ->to($emailAlumno)
                ->subject('Recordatorio de Pago Pendiente - ' . $instituto->getNombre())
                ->htmlTemplate('emails/recordatorio_deuda.html.twig')
                ->context([
                    'instituto' => $instituto,
                    'alumno' => $alumno,
                    'deuda' => $deuda,
                    'logoUrl' => $logoUrl,
                    'textoPersonalizado' => $configuracion ? $configuracion->getTextoPersonalizadoEmail() : null,
                ]);
            
            $this->mailer->send($email);
            
            // Consumir tokens por enviar notificación
            $this->tokenService->consumeTokens(
                $instituto,
                'notificacion.send',
                null,
                'Enviar recordatorio de deuda a ' . $alumno->getNombreApellido(),
                'DeudaAlumno',
                $deuda->getId()
            );
            
            return true;
        } catch (\Exception $e) {
            // Log error si es necesario
            throw $e; // Re-lanzar para que el comando pueda mostrar el error
        }
    }

    /**
     * Procesa y envía recordatorios automáticos según la configuración
     * Se ejecuta diariamente para verificar deudas que requieren notificación
     */
    public function procesarRecordatoriosAutomaticos(): int
    {
        $enviados = 0;
        $fechaActual = new \DateTime();
        $diaActual = (int)$fechaActual->format('d');
        
        // Obtener todos los institutos activos
        $institutos = $this->entityManager->getRepository(Instituto::class)->findAll();
        
        foreach ($institutos as $instituto) {
            $configuracion = $instituto->getConfiguracion();
            
            if (!$configuracion || !$configuracion->getEnviarRecordatorioEnDiaVencimiento()) {
                continue;
            }
            
            // Obtener el primer día de vencimiento del instituto
            $vencimientos = $instituto->getVencimientos()->toArray();
            if (empty($vencimientos)) {
                continue;
            }
            
            usort($vencimientos, function($a, $b) {
                return $a->getOrden() <=> $b->getOrden();
            });
            
            $primerVencimiento = reset($vencimientos);
            $diaVencimiento = $primerVencimiento->getDiaVencimiento();
            
            // Solo enviar si estamos en el día de vencimiento
            if ($diaActual != $diaVencimiento) {
                continue;
            }
            
            // Obtener todas las deudas pendientes del mes actual
            $mesActual = (int)$fechaActual->format('n');
            $anoActual = (int)$fechaActual->format('Y');
            
            $deudas = $this->entityManager->getRepository(DeudaAlumno::class)
                ->createQueryBuilder('d')
                ->leftJoin('d.alumno', 'a')
                ->leftJoin('d.aplicaciones', 'pa')
                ->groupBy('d.id')
                ->having('COALESCE(SUM(pa.montoAplicado), 0) < d.monto + COALESCE(d.interes, 0)')
                ->andWhere('a.instituto = :instituto')
                ->andWhere('a.activo = :activo')
                ->andWhere('d.mes = :mes')
                ->andWhere('d.ano = :ano')
                ->setParameter('instituto', $instituto)
                ->setParameter('activo', true)
                ->setParameter('mes', $mesActual)
                ->setParameter('ano', $anoActual)
                ->getQuery()
                ->getResult();
            
            foreach ($deudas as $deuda) {
                if ($this->enviarRecordatorioDeuda($deuda->getAlumno(), $deuda)) {
                    $enviados++;
                }
            }
        }
        
        return $enviados;
    }

    /**
     * Obtiene la URL del logo del instituto
     */
    private function getLogoUrl(Instituto $instituto): ?string
    {
        if (!$instituto->getLogo()) {
            return null;
        }
        
        // Construir la URL completa del logo
        return $this->baseUrl . '/uploads/logos/' . $instituto->getLogo();
    }
}

