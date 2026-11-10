<?php
namespace App\Service;
use App\Entity\{SolicitudPaginaPublica, RespuestaSolicitudPublica, MensajeEntranteSolicitud};
use Doctrine\DBAL\LockMode;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/** Solo lo invoca el comando CLI local. No se publica un endpoint de recepción. */
class ImportarCorreoConversacion
{
    public function __construct(private EntityManagerInterface $em, private CorreoConversaciones $correo, private EmailService $emails, private LoggerInterface $logger, private string $projectDir, private string $baseUrl) {}
    public function importar(array $datos): array
    {
        if (!$this->correo->isActivo()) { return ['estado'=>'deshabilitado']; }
        if (($datos['casilla']??null)!==$this->correo->casilla()) { return ['estado'=>'ignorado','motivo'=>'casilla_incorrecta']; }
        if (!empty($datos['automatico'])) { return ['estado'=>'ignorado','motivo'=>'automatico']; }
        $remitente=mb_strtolower(trim(is_string($datos['remitente']??null)?$datos['remitente']:''));
        if (!filter_var($remitente,FILTER_VALIDATE_EMAIL) || mb_strlen($remitente)>180) { return ['estado'=>'ignorado','motivo'=>'remitente_invalido']; }
        $tokens=array_values(array_filter(array_slice(is_array($datos['tokens']??null)?$datos['tokens']:[],0,20),static fn($t)=>is_string($t)&&preg_match('/^[a-f0-9]{32}$/D',$t)));
        $referencias=array_values(array_filter(array_slice(is_array($datos['referencias']??null)?$datos['referencias']:[],0,50),static fn($t)=>is_string($t)&&strlen($t)<=180&&!preg_match('/[\s<>]/',$t)));
        $candidatas=[];
        if ($tokens) { foreach ($this->em->getRepository(SolicitudPaginaPublica::class)->findBy(['correoToken'=>$tokens]) as $s) { $candidatas[$s->getId()]=$s; } }
        if ($referencias) { foreach ($this->em->getRepository(RespuestaSolicitudPublica::class)->findBy(['correoMessageId'=>$referencias]) as $r) { $s=$r->getSolicitud();$candidatas[$s->getId()]=$s; } }
        if (count($candidatas)!==1) { return ['estado'=>'ignorado','motivo'=>$candidatas?'conversacion_ambigua':'sin_conversacion']; }
        $solicitud=reset($candidatas);
        if (mb_strtolower(trim($solicitud->getEmail()))!==$remitente) { return ['estado'=>'ignorado','motivo'=>'remitente_no_coincide']; }
        if (!$solicitud->getInstituto()->isActivo()) { return ['estado'=>'ignorado','motivo'=>'instituto_inactivo']; }
        $raw=is_string($datos['original']??null)?base64_decode($datos['original'],true):false;
        if ($raw===false || $raw==='' || strlen($raw)>5*1024*1024) { return ['estado'=>'ignorado','motivo'=>'original_invalido_o_extenso']; }
        $messageId=is_string($datos['message_id']??null)?trim($datos['message_id']):'';
        if (strlen($messageId)>255 || preg_match('/[\r\n]/',$messageId)) { return ['estado'=>'ignorado','motivo'=>'identificador_invalido']; }
        $huella=hash('sha256',$this->correo->casilla().'|'.($messageId!==''?$messageId:hash('sha256',$raw)));
        $db=$this->em->getConnection();$archivo=null;$db->beginTransaction();
        try {
            $this->em->lock($solicitud,LockMode::PESSIMISTIC_WRITE);$this->em->refresh($solicitud);
            if ($this->em->getRepository(MensajeEntranteSolicitud::class)->findOneBy(['huella'=>$huella])) { $db->commit();return ['estado'=>'duplicado']; }
            $carpeta=$this->projectDir.'/var/correo_recibido';
            if (!is_dir($carpeta) && !mkdir($carpeta,0700,true) && !is_dir($carpeta)) { throw new \RuntimeException('No se pudo crear el archivo privado de correo.'); }
            $nombre=bin2hex(random_bytes(32)).'.eml';$archivo=$carpeta.'/'.$nombre;
            if (file_put_contents($archivo,$raw,LOCK_EX)===false) { throw new \RuntimeException('No se pudo guardar el correo original.'); }
            chmod($archivo,0600);
            $adjuntos=[];
            foreach(array_slice(is_array($datos['adjuntos']??null)?$datos['adjuntos']:[],0,30) as $a) { if(is_string($a)) { $adjuntos[]=mb_substr(preg_replace('/[\x00-\x1f\x7f]/u','',$a),0,180); } }
            $asunto=mb_substr(str_replace(["\r","\n"],' ',is_string($datos['asunto']??null)?$datos['asunto']:''),0,255);
            $texto=is_string($datos['mensaje']??null)?$datos['mensaje']:'';
            if (mb_strlen($texto)>50000) { $texto=mb_substr($texto,0,50000)."\n[Mensaje abreviado. Descargá el correo original para ver el contenido completo.]"; }
            if (trim($texto)==='') { $texto='Este correo no contiene texto para mostrar. Podés descargar el original.'; }
            $mensaje=new MensajeEntranteSolicitud($solicitud,$remitente,$asunto,$texto,$huella,$nombre,$adjuntos);
            $this->em->persist($mensaje);$solicitud->recibirCorreo();$this->em->flush();$db->commit();
        } catch (UniqueConstraintViolationException $e) { $db->rollBack();if($archivo){@unlink($archivo);}return ['estado'=>'duplicado']; }
        catch (\Throwable $e) { $db->rollBack();if($archivo){@unlink($archivo);}throw $e; }
        $destino=$solicitud->getInstituto()->getEmail();
        if (filter_var($destino,FILTER_VALIDATE_EMAIL) && mb_strtolower($destino)!==$this->correo->casilla()) {
            try { $this->emails->sendGeneralCommunication($destino,'Nueva respuesta en una conversación','emails/solicitud_correo_entrante.html.twig',['solicitud'=>$solicitud,'bandejaUrl'=>rtrim($this->baseUrl,'/').'/instituto/solicitudes/'.$solicitud->getId()]); }
            catch (\Throwable $e) { $this->logger->warning('No se pudo avisar sobre un correo entrante.', ['solicitud_id'=>$solicitud->getId()]); }
        }
        return ['estado'=>'importado','mensaje_id'=>$mensaje->getId()];
    }
}
