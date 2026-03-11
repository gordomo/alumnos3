<?php

namespace App\Service;

use App\Entity\Instituto;
use App\Entity\Alumno;
use App\Entity\AlumnosPagos;
use App\Entity\DeudaAlumno;
use App\Entity\EmailLog;
use App\Entity\User;
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
    public function enviarReciboPago(AlumnosPagos $pago, ?string $emailDestino = null, bool $forzarEnvio = false, ?User $solicitadoPor = null): bool
    {
        $instituto = $pago->getAlumno()->getInstituto();
        $configuracion = $instituto->getConfiguracion();
        
        // Verificar si está habilitado el envío de facturas/recibos (a menos que se fuerce)
        if (!$forzarEnvio && (!$configuracion || !$configuracion->getEnviarFacturasRecibos())) {
            $this->registrarEmailLog($instituto, $pago->getAlumno(), 'recibo', $emailDestino ?? $pago->getAlumno()->getEmail(), 
                'Recibo de Pago - ' . $instituto->getNombre(), 'fallido', 
                'Envío de recibos deshabilitado en configuración', $pago, null, $solicitadoPor, false);
            return false;
        }
        
        $alumno = $pago->getAlumno();
        $emailAlumno = $emailDestino ?? $alumno->getEmail();
        
        if (!$emailAlumno) {
            $this->registrarEmailLog($instituto, $alumno, 'recibo', 'Sin email', 
                'Recibo de Pago - ' . $instituto->getNombre(), 'fallido', 
                'El alumno no tiene email configurado', $pago, null, $solicitadoPor, false);
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
        $asunto = 'Recibo de Pago - ' . $instituto->getNombre();
        
        try {
            $logoUrl = $this->getLogoUrl($instituto);
            
            $email = (new TemplatedEmail())
                ->from(new Address($fromEmail, $fromName))
                ->to($emailAlumno)
                ->subject($asunto)
                ->htmlTemplate('emails/recibo_pago.html.twig')
                ->context([
                    'instituto' => $instituto,
                    'pago' => $pago,
                    'alumno' => $alumno,
                    'logoUrl' => $logoUrl,
                    'textoPersonalizado' => $configuracion ? $configuracion->getTextoPersonalizadoEmail() : null,
                ]);
            
            $this->mailer->send($email);
            
            // Registrar email enviado exitosamente
            $this->registrarEmailLog($instituto, $alumno, 'recibo', $emailAlumno, $asunto, 
                'enviado', null, $pago, null, $solicitadoPor, $solicitadoPor === null);
            
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
            // Registrar email fallido
            $this->registrarEmailLog($instituto, $alumno, 'recibo', $emailAlumno, $asunto, 
                'fallido', $e->getMessage(), $pago, null, $solicitadoPor, $solicitadoPor === null);
            
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
    public function enviarRecordatorioDeuda(Alumno $alumno, DeudaAlumno $deuda, ?string $emailDestino = null, bool $forzarEnvio = false, ?User $solicitadoPor = null): bool
    {
        $instituto = $alumno->getInstituto();
        $configuracion = $instituto->getConfiguracion();
        
        // Verificar si está habilitado el envío de recordatorios (a menos que se fuerce)
        if (!$forzarEnvio && (!$configuracion || !$configuracion->getEnviarRecordatoriosDeudas())) {
            $this->registrarEmailLog($instituto, $alumno, 'recordatorio', $emailDestino ?? $alumno->getEmail(), 
                'Recordatorio de Pago Pendiente - ' . $instituto->getNombre(), 'fallido', 
                'Envío de recordatorios deshabilitado en configuración', null, $deuda, $solicitadoPor, false);
            return false;
        }
        
        $emailAlumno = $emailDestino ?? $alumno->getEmail();
        
        if (!$emailAlumno) {
            $this->registrarEmailLog($instituto, $alumno, 'recordatorio', 'Sin email', 
                'Recordatorio de Pago Pendiente - ' . $instituto->getNombre(), 'fallido', 
                'El alumno no tiene email configurado', null, $deuda, $solicitadoPor, false);
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
        $asunto = 'Recordatorio de Pago Pendiente - ' . $instituto->getNombre();
        
        try {
            $logoUrl = $this->getLogoUrl($instituto);
            
            $email = (new TemplatedEmail())
                ->from(new Address($fromEmail, $fromName))
                ->to($emailAlumno)
                ->subject($asunto)
                ->htmlTemplate('emails/recordatorio_deuda.html.twig')
                ->context([
                    'instituto' => $instituto,
                    'alumno' => $alumno,
                    'deuda' => $deuda,
                    'logoUrl' => $logoUrl,
                    'textoPersonalizado' => $configuracion ? $configuracion->getTextoPersonalizadoEmail() : null,
                ]);
            
            $this->mailer->send($email);
            
            // Registrar email enviado exitosamente
            $this->registrarEmailLog($instituto, $alumno, 'recordatorio', $emailAlumno, $asunto, 
                'enviado', null, null, $deuda, $solicitadoPor, $solicitadoPor === null);
            
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
            // Registrar email fallido
            $this->registrarEmailLog($instituto, $alumno, 'recordatorio', $emailAlumno, $asunto, 
                'fallido', $e->getMessage(), null, $deuda, $solicitadoPor, $solicitadoPor === null);
            
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
            
            // Verificar si tiene activado el envío automático de recordatorios
            if (!$configuracion || !$configuracion->getEnviarRecordatoriosDeudas()) {
                continue;
            }
            
            // Obtener todas las deudas pendientes (no solo del mes actual)
            $deudas = $this->entityManager->getRepository(DeudaAlumno::class)
                ->createQueryBuilder('d')
                ->leftJoin('d.alumno', 'a')
                ->leftJoin('d.aplicaciones', 'pa')
                ->groupBy('d.id')
                ->having('COALESCE(SUM(pa.montoAplicado), 0) < d.monto + COALESCE(d.interes, 0)')
                ->andWhere('a.instituto = :instituto')
                ->andWhere('a.activo = :activo')
                ->andWhere('a.email IS NOT NULL')
                ->andWhere('a.email != :empty')
                ->setParameter('instituto', $instituto)
                ->setParameter('activo', true)
                ->setParameter('empty', '')
                ->getQuery()
                ->getResult();
            
            foreach ($deudas as $deuda) {
                $debeEnviar = false;
                
                // Opción 1: Enviar cada 3 días desde la creación o último envío
                if ($this->debeEnviarRecordatorio($deuda)) {
                    $debeEnviar = true;
                }
                
                // Opción 2: Enviar en el día de vencimiento (si está activado)
                if ($configuracion->getEnviarRecordatorioEnDiaVencimiento()) {
                    $vencimientos = $instituto->getVencimientos()->toArray();
                    if (!empty($vencimientos)) {
                        usort($vencimientos, function($a, $b) {
                            return $a->getOrden() <=> $b->getOrden();
                        });
                        $primerVencimiento = reset($vencimientos);
                        $diaVencimiento = $primerVencimiento->getDiaVencimiento();
                        
                        // Si estamos en el día de vencimiento, enviar
                        if ($diaActual == $diaVencimiento) {
                            $debeEnviar = true;
                        }
                    }
                }
                
                if ($debeEnviar) {
                    if ($this->enviarRecordatorioDeuda($deuda->getAlumno(), $deuda, true)) {
                        $enviados++;
                    }
                }
            }
        }
        
        return $enviados;
    }

    /**
     * Verifica si debe enviarse un recordatorio para una deuda
     * Se envía cada 3 días desde la creación de la deuda o desde el último recordatorio
     */
    private function debeEnviarRecordatorio(DeudaAlumno $deuda): bool
    {
        $fechaActual = new \DateTime();
        
        // Buscar el último recordatorio enviado para esta deuda
        $ultimoRecordatorio = $this->entityManager->getRepository(EmailLog::class)
            ->createQueryBuilder('e')
            ->where('e.deuda = :deuda')
            ->andWhere('e.tipo = :tipo')
            ->andWhere('e.estado = :estado')
            ->setParameter('deuda', $deuda)
            ->setParameter('tipo', 'recordatorio')
            ->setParameter('estado', 'enviado')
            ->orderBy('e.fechaEnvio', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
        
        // Si nunca se envió recordatorio, verificar desde la fecha de creación de la deuda
        if (!$ultimoRecordatorio) {
            $fechaReferencia = $deuda->getFechaCreacion();
        } else {
            $fechaReferencia = $ultimoRecordatorio->getFechaEnvio();
        }
        
        // Calcular días transcurridos
        $diasTranscurridos = $fechaActual->diff($fechaReferencia)->days;
        
        // Enviar si han pasado 3 o más días
        return $diasTranscurridos >= 3;
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

    /**
     * Registra un email en el log
     */
    private function registrarEmailLog(
        Instituto $instituto,
        ?Alumno $alumno,
        string $tipo,
        string $destinatario,
        string $asunto,
        string $estado,
        ?string $errorMensaje = null,
        ?AlumnosPagos $pago = null,
        ?DeudaAlumno $deuda = null,
        ?User $solicitadoPor = null,
        bool $esAutomatico = false
    ): void {
        try {
            $emailLog = new EmailLog();
            $emailLog->setInstituto($instituto);
            $emailLog->setAlumno($alumno);
            $emailLog->setTipo($tipo);
            $emailLog->setDestinatario($destinatario);
            $emailLog->setAsunto($asunto);
            $emailLog->setFechaEnvio(new \DateTime());
            $emailLog->setEstado($estado);
            $emailLog->setErrorMensaje($errorMensaje);
            $emailLog->setPago($pago);
            $emailLog->setDeuda($deuda);
            $emailLog->setSolicitadoPor($solicitadoPor);
            $emailLog->setEsAutomatico($esAutomatico);
            
            $this->entityManager->persist($emailLog);
            $this->entityManager->flush();
        } catch (\Exception $e) {
            // No interrumpir el flujo si falla el registro del log
            // Podría loggear este error en un archivo si es necesario
        }
    }
}

