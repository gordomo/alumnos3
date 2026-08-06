<?php

namespace App\Service;

use App\Entity\Instituto;
use App\Entity\Alumno;
use App\Entity\AlumnoCursoHistorico;
use App\Entity\AlumnosPagos;
use App\Entity\DeudaAlumno;
use App\Entity\EmailLog;
use App\Entity\InstitutoConfiguracion;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mime\Address;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use App\Service\TokenService;
use App\Service\InstitutoTimezoneService;

class NotificationService
{
    private MailerInterface $mailer;
    private EntityManagerInterface $entityManager;
    private UrlGeneratorInterface $urlGenerator;
    private TokenService $tokenService;
    private InstitutoTimezoneService $institutoTimezoneService;
    private string $baseUrl;
    private $deudaCalculator;

    public function __construct(
        MailerInterface $mailer,
        EntityManagerInterface $entityManager,
        UrlGeneratorInterface $urlGenerator,
        TokenService $tokenService,
        InstitutoTimezoneService $institutoTimezoneService,
        DeudaCalculatorService $deudaCalculator
    ) {
        $this->mailer = $mailer;
        $this->entityManager = $entityManager;
        $this->urlGenerator = $urlGenerator;
        $this->tokenService = $tokenService;
        $this->institutoTimezoneService = $institutoTimezoneService;
        $this->deudaCalculator = $deudaCalculator;
        
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
        $destinatarios = $this->resolverDestinatarios($alumno, $emailDestino, $configuracion);
        $emailAlumno = implode(', ', $destinatarios);

        if (!$destinatarios) {
            $this->registrarEmailLog($instituto, $alumno, 'recibo', 'Sin email',
                'Recibo de Pago - ' . $instituto->getNombre(), 'fallido',
                'No hay ninguna dirección válida para notificar según la configuración del instituto', $pago, null, $solicitadoPor, false);
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
                ->to(...$destinatarios)
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
     * Envía el boletín de notas de un alumno en un curso.
     *
     * Va por defecto al email del tutor, con fallback al del alumno. Sigue el mismo patrón
     * que enviarReciboPago(): registra el envío en EmailLog con tipo 'boletin', tanto si
     * sale bien como si falla, y no interrumpe el flujo si el registro del log falla.
     *
     * @param array $resumen resultado de PromedioCalificacionService
     * @param array $calificaciones las notas a listar
     */
    public function enviarBoletinNotas(
        AlumnoCursoHistorico $historico,
        array $calificaciones,
        array $resumen,
        ?string $emailDestino = null,
        ?User $solicitadoPor = null
    ): bool {
        $alumno = $historico->getAlumno();
        $curso = $historico->getCurso();
        $instituto = $alumno->getInstituto();
        $configuracion = $instituto->getConfiguracion();

        $asunto = sprintf('Boletín de notas - %s - %s', $curso->getNombre(), $instituto->getNombre());

        // Igual que el recibo y el recordatorio: quién recibe lo decide la configuración del
        // instituto. Antes acá el tutor tapaba al alumno y nunca llegaba a los dos.
        $destinatarios = $this->resolverDestinatarios($alumno, $emailDestino, $configuracion);
        $destino = implode(', ', $destinatarios);

        if (!$destinatarios) {
            $this->registrarEmailLog(
                $instituto, $alumno, 'boletin', 'Sin email', $asunto,
                'fallido', 'No hay ninguna dirección válida para notificar según la configuración del instituto',
                null, null, $solicitadoPor, false
            );

            return false;
        }

        $institutoEmail = $instituto->getEmail();
        $fromEmail = 'noreply@teambuilder.com.ar';
        if ($institutoEmail && filter_var($institutoEmail, FILTER_VALIDATE_EMAIL)) {
            $emailDomain = substr(strrchr($institutoEmail, '@'), 1);
            if (in_array($emailDomain, ['teambuilder.com.ar', 'dattaweb.com'])) {
                $fromEmail = $institutoEmail;
            }
        }

        try {
            $email = (new TemplatedEmail())
                ->from(new Address($fromEmail, $instituto->getNombre() ?? 'Instituto'))
                ->to(...$destinatarios)
                ->subject($asunto)
                ->htmlTemplate('emails/boletin_notas.html.twig')
                ->context([
                    'instituto' => $instituto,
                    'alumno' => $alumno,
                    'curso' => $curso,
                    'historico' => $historico,
                    'calificaciones' => $calificaciones,
                    'resumen' => $resumen,
                    'logoUrl' => $this->getLogoUrl($instituto),
                    'dateFormat' => $this->institutoTimezoneService->getDateFormatForInstituto($instituto),
                    'textoPersonalizado' => $configuracion ? $configuracion->getTextoPersonalizadoEmail() : null,
                ]);

            $this->mailer->send($email);

            $this->registrarEmailLog(
                $instituto, $alumno, 'boletin', $destino, $asunto,
                'enviado', null, null, null, $solicitadoPor, false
            );

            return true;
        } catch (\Exception $e) {
            $this->registrarEmailLog(
                $instituto, $alumno, 'boletin', $destino, $asunto,
                'fallido', $e->getMessage(), null, null, $solicitadoPor, false
            );

            return false;
        }
    }

    /**
     * Envía un recordatorio de deuda pendiente
     */
    /**
     * @param array|null $resumenVencidas ['cantidad' => int, 'total' => float] cuando el alumno
     *                                    debe varias cuotas y se le manda un solo recordatorio.
     */
    public function enviarRecordatorioDeuda(Alumno $alumno, DeudaAlumno $deuda, ?string $emailDestino = null, bool $forzarEnvio = false, ?User $solicitadoPor = null, ?array $resumenVencidas = null): bool
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
        
        $destinatarios = $this->resolverDestinatarios($alumno, $emailDestino, $configuracion);
        $emailAlumno = implode(', ', $destinatarios);

        if (!$destinatarios) {
            $this->registrarEmailLog($instituto, $alumno, 'recordatorio', 'Sin email',
                'Recordatorio de Pago Pendiente - ' . $instituto->getNombre(), 'fallido',
                'No hay ninguna dirección válida para notificar según la configuración del instituto', null, $deuda, $solicitadoPor, false);
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
                ->to(...$destinatarios)
                ->subject($asunto)
                ->htmlTemplate('emails/recordatorio_deuda.html.twig')
                ->context([
                    'instituto' => $instituto,
                    'alumno' => $alumno,
                    'deuda' => $deuda,
                    'logoUrl' => $logoUrl,
                    // Cuando el recordatorio sale del proceso automático, el alumno recibe uno
                    // solo aunque deba varias cuotas, así que el mail tiene que decir cuántas.
                    'resumenVencidas' => $resumenVencidas,
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
     * Procesa y envía los recordatorios de deuda automáticos. Lo corre a diario el comando
     * app:send-notifications.
     *
     * Tres reglas, todas configurables por instituto, y basta que se cumpla una:
     *  - Días del mes fijos (recordatorioDiasMes), para el clásico "avisales después del 20".
     *  - El día del primer vencimiento (enviarRecordatorioEnDiaVencimiento).
     *  - Cada N días desde el último recordatorio de esa cuota (recordatorioCadaDias).
     *
     * Además el alumno tiene que llegar al mínimo de cuotas vencidas configurado, y recibe
     * un solo mail por corrida aunque deba varias: se manda por la cuota más vieja e incluye
     * cuántas debe en total. Antes se mandaba uno por cuota, o sea tres mails a un padre con
     * tres meses atrasados.
     */
    public function procesarRecordatoriosAutomaticos(): int
    {
        $enviados = 0;

        foreach ($this->entityManager->getRepository(Instituto::class)->findAll() as $instituto) {
            $configuracion = $instituto->getConfiguracion();

            if (!$configuracion || !$configuracion->getEnviarRecordatoriosDeudas()) {
                continue;
            }

            // El día se evalúa en la zona horaria del instituto: con institutos en husos
            // distintos, "hoy es 20" no es lo mismo para todos.
            $diaActual = (int) $this->institutoTimezoneService->getNowForInstituto($instituto)->format('d');

            $esDiaDeAviso = in_array($diaActual, $configuracion->getRecordatorioDiasMesArray(), true);

            if (!$esDiaDeAviso && $configuracion->getEnviarRecordatorioEnDiaVencimiento()) {
                $esDiaDeAviso = $diaActual === $this->primerDiaVencimiento($instituto);
            }

            $alumnos = $this->entityManager->getRepository(Alumno::class)->findBy([
                'instituto' => $instituto,
                'activo' => true,
            ]);

            foreach ($alumnos as $alumno) {
                $this->deudaCalculator->sincronizarDeudasConTabla($alumno);
            }

            $vencidasPorAlumno = $this->deudasVencidasPorAlumno($instituto);

            foreach ($vencidasPorAlumno as $datos) {
                if (count($datos['deudas']) < $configuracion->getRecordatorioMinCuotasVencidas()) {
                    continue;
                }

                // La más vieja: es la que conviene mostrar y la que ancla el "cada N días".
                $deuda = $datos['deudas'][0];

                $debeEnviar = $esDiaDeAviso;

                if (!$debeEnviar && $configuracion->getRecordatorioCadaDias()) {
                    $debeEnviar = $this->debeEnviarRecordatorio($deuda, $configuracion->getRecordatorioCadaDias());
                }

                if (!$debeEnviar) {
                    continue;
                }

                $resumen = [
                    'cantidad' => count($datos['deudas']),
                    'total' => $datos['total'],
                ];

                // forzarEnvio va en true porque el switch del instituto ya se chequeó arriba.
                // Antes este true se pasaba en la posición de $emailDestino, así que sin
                // strict_types PHP lo convertía en la cadena "1" y todos los recordatorios
                // automáticos se intentaban mandar a esa dirección y fallaban.
                if ($this->enviarRecordatorioDeuda($datos['alumno'], $deuda, null, true, null, $resumen)) {
                    $enviados++;
                }
            }
        }

        return $enviados;
    }

    /**
     * Día del mes del primer vencimiento del instituto, o null si no tiene ninguno cargado.
     */
    private function primerDiaVencimiento(Instituto $instituto): ?int
    {
        $vencimientos = $instituto->getVencimientos()->toArray();
        if (!$vencimientos) {
            return null;
        }

        usort($vencimientos, static function ($a, $b) {
            return $a->getOrden() <=> $b->getOrden();
        });

        return (int) reset($vencimientos)->getDiaVencimiento();
    }

    /**
     * Cuotas impagas y ya vencidas del instituto, agrupadas por alumno y ordenadas de la más
     * vieja a la más nueva.
     *
     * Impaga = lo aplicado por pagos no cubre monto + interés. Vencida = de un mes anterior al
     * actual, o del mes actual con el día del primer vencimiento ya cumplido; es el mismo
     * criterio que usa la pantalla de deudas, para que el mail no diga algo distinto de la app.
     *
     * @return array<int, array{alumno: Alumno, deudas: DeudaAlumno[], total: float}>
     */
    private function deudasVencidasPorAlumno(Instituto $instituto): array
    {
        $hoy = $this->institutoTimezoneService->getNowForInstituto($instituto);
        $mesActual = (int) $hoy->format('n');
        $anoActual = (int) $hoy->format('Y');
        $diaActual = (int) $hoy->format('d');
        $diaVencimiento = $this->primerDiaVencimiento($instituto) ?? 5;

        $deudas = $this->entityManager->getRepository(DeudaAlumno::class)
            ->createQueryBuilder('d')
            ->leftJoin('d.alumno', 'a')
            ->leftJoin('d.aplicaciones', 'pa')
            ->groupBy('d.id')
            ->having('COALESCE(SUM(pa.montoAplicado), 0) < d.monto + COALESCE(d.interes, 0)')
            ->andWhere('a.instituto = :instituto')
            ->andWhere('a.activo = :activo')
            // Alcanza con que haya email del alumno o del tutor: cuál se usa lo decide
            // resolverDestinatarios() según la configuración.
            ->andWhere('(a.email IS NOT NULL AND a.email != :vacio) OR (a.corre_tutor IS NOT NULL AND a.corre_tutor != :vacio)')
            ->setParameter('instituto', $instituto)
            ->setParameter('activo', true)
            ->setParameter('vacio', '')
            ->orderBy('d.ano', 'ASC')
            ->addOrderBy('d.mes', 'ASC')
            ->getQuery()
            ->getResult();

        $porAlumno = [];
        foreach ($deudas as $deuda) {
            $ano = (int) $deuda->getAno();
            $mes = (int) $deuda->getMes();

            $esMesAnterior = $ano < $anoActual || ($ano === $anoActual && $mes < $mesActual);
            $esMesActualVencido = $ano === $anoActual && $mes === $mesActual && $diaActual >= $diaVencimiento;

            if (!$esMesAnterior && !$esMesActualVencido) {
                continue;
            }

            $alumno = $deuda->getAlumno();
            $id = $alumno->getId();

            if (!isset($porAlumno[$id])) {
                $porAlumno[$id] = ['alumno' => $alumno, 'deudas' => [], 'total' => 0.0];
            }

            $porAlumno[$id]['deudas'][] = $deuda;
            $porAlumno[$id]['total'] += (float) $deuda->getMonto() + (float) $deuda->getInteres();
        }

        return $porAlumno;
    }

    /**
     * Si toca repetir el recordatorio de esta cuota: cuentan los días desde el último
     * recordatorio enviado, o desde que se creó la deuda si nunca se mandó ninguno.
     *
     * $cadaDias antes estaba fijo en 3 acá adentro; ahora lo define el instituto.
     */
    private function debeEnviarRecordatorio(DeudaAlumno $deuda, int $cadaDias): bool
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
        
        return $diasTranscurridos >= $cadaDias;
    }

    /**
     * Direcciones a las que va una notificación de este alumno.
     *
     * $override gana sobre todo: lo usa el reenvío manual desde el historial de emails,
     * que apunta a una dirección concreta elegida por el operador.
     *
     * Sin override manda la configuración del instituto: 'alumno', 'tutor' o 'ambos'.
     * Se descartan las direcciones inválidas y las repetidas, así que un alumno cuyo email
     * es el mismo que el del tutor recibe una sola copia y no dos.
     *
     * Devuelve lista vacía si no hay ninguna dirección usable; el llamador lo registra
     * como fallido en el log en vez de intentar un envío que va a explotar.
     *
     * @return string[]
     */
    private function resolverDestinatarios(
        Alumno $alumno,
        ?string $override,
        ?InstitutoConfiguracion $configuracion
    ): array {
        if ($override !== null && trim($override) !== '') {
            $override = trim($override);

            return filter_var($override, FILTER_VALIDATE_EMAIL) ? [$override] : [];
        }

        $modo = $configuracion ? $configuracion->getNotificarA() : 'alumno';

        $candidatos = [];
        if ($modo === 'alumno' || $modo === 'ambos') {
            $candidatos[] = $alumno->getEmail();
        }
        if ($modo === 'tutor' || $modo === 'ambos') {
            $candidatos[] = $alumno->getCorreTutor();
        }
        // Con 'tutor' y sin email de tutor cargado, se cae al del alumno: es mejor que la
        // notificación llegue a alguien que a nadie.
        if ($modo === 'tutor' && !$alumno->getCorreTutor()) {
            $candidatos[] = $alumno->getEmail();
        }

        $destinatarios = [];
        foreach ($candidatos as $candidato) {
            $email = trim((string) $candidato);
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                continue;
            }
            if (!in_array($email, $destinatarios, true)) {
                $destinatarios[] = $email;
            }
        }

        return $destinatarios;
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
            $emailLog->setFechaEnvio($this->institutoTimezoneService->getNowForInstituto($instituto));
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

