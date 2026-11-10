<?php
namespace App\Service;
use App\Entity\{SolicitudPaginaPublica, RespuestaSolicitudPublica, User, EmailLog};
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

class RespuestaSolicitudService
{
    public function __construct(private EntityManagerInterface $em, private EmailService $emails, private LoggerInterface $logger, private ?CorreoConversaciones $correo=null) {}
    public function enviar(SolicitudPaginaPublica $solicitud, User $autor, string $asunto, string $mensaje, string $nonce): RespuestaSolicitudPublica
    {
        if (!$autor->getInstituto() || $autor->getInstituto()->getId()!==$solicitud->getInstituto()->getId()) { throw new \LogicException('La solicitud no pertenece a este instituto.'); }
        $asunto=trim($asunto);$mensaje=trim($mensaje);
        if ($asunto==='' || mb_strlen($asunto)>180 || preg_match('/[\r\n]/',$asunto) || $mensaje==='' || mb_strlen($mensaje)>5000 || !preg_match('/^[a-f0-9]{32}$/D',$nonce)) { throw new \InvalidArgumentException('Revisá el asunto y el mensaje.'); }
        if (!($this->correo && $this->correo->isActivo()) && !filter_var($solicitud->getInstituto()->getEmail(),FILTER_VALIDATE_EMAIL)) { throw new \InvalidArgumentException('Configurá un email válido del instituto en Información general.'); }
        $clave=hash('sha256',$solicitud->getId().'|'.$nonce);
        $db=$this->em->getConnection();$db->beginTransaction();
        try {
            $this->em->lock($solicitud,LockMode::PESSIMISTIC_WRITE);
            $previa=$this->em->getRepository(RespuestaSolicitudPublica::class)->findOneBy(['claveEnvio'=>$clave]);
            if ($previa) { $db->commit();return $previa; }
            $this->em->refresh($solicitud);
            $respuesta=new RespuestaSolicitudPublica($solicitud,$autor,$asunto,$mensaje,$clave);
            if ($this->correo && $this->correo->isActivo()) { $solicitud->prepararCorreo();$respuesta->prepararCorreo(); }
            $this->em->persist($respuesta);$this->em->flush();$db->commit();
        } catch (\Throwable $e) { $db->rollBack();throw $e; }
        // La reserva queda guardada antes del SMTP: repetir un POST no vuelve a enviar el mensaje.
        try {
            $this->emails->sendSolicitudReply($respuesta);
        } catch (\Throwable $e) {
            $respuesta->marcarFallida();
            $this->registrar($respuesta,$autor,'fallido');$this->em->flush();
            $this->logger->warning('Falló el envío de una respuesta a una solicitud.', ['respuesta_id'=>$respuesta->getId()]);
            return $respuesta;
        }
        $db->beginTransaction();
        try {
            $this->em->lock($solicitud,LockMode::PESSIMISTIC_WRITE);$this->em->refresh($solicitud);
            $respuesta->marcarEnviada();$solicitud->marcarCorreosLeidos();
            if ($solicitud->getTipo()==='contacto') { $solicitud->resolver('atendida'); }
            $this->registrar($respuesta,$autor,'enviado');$this->em->flush();$db->commit();
        } catch (\Throwable $e) { $db->rollBack();throw $e; }
        return $respuesta;
    }
    private function registrar(RespuestaSolicitudPublica $r, User $autor, string $estado): void
    {
        $log=(new EmailLog())->setInstituto($r->getSolicitud()->getInstituto())->setTipo('respuesta_consulta')->setDestinatario($r->getDestinatario())->setAsunto($r->getAsunto())->setFechaEnvio(new \DateTime())->setEstado($estado)->setSolicitadoPor($autor)->setEsAutomatico(false);
        if ($estado==='fallido') { $log->setErrorMensaje('No se pudo confirmar el envío. Revisá el estado del correo antes de volver a intentarlo.'); }
        $this->em->persist($log);
    }
}
