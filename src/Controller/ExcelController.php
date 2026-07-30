<?php

namespace App\Controller;

use App\Entity\Alumno;
use App\Repository\AlumnoRepository;
use App\Repository\CursoRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\Persistence\ManagerRegistry;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;


/**
 * @Route("/instituto/excel")
 */
#[IsGranted('ROLE_ADMIN_INSTITUTO')]
class ExcelController extends AbstractController
{
    /**
     * Extensiones aceptadas para la planilla de importación.
     */
    private const EXTENSIONES_PERMITIDAS = ['xlsx', 'xls', 'csv'];

    /**
     * Tamaño máximo del archivo (10 MB).
     */
    private const TAMANO_MAXIMO = 10 * 1024 * 1024;

    /**
     * @Route("/import/alumnos", name="app_excel_import_alumnos", methods={"POST"})
     */
    public function import(Request $request, CursoRepository $cursoRepository, AlumnoRepository $alumnoRepository, ManagerRegistry $doctrine): Response
    {
        if (!$this->isCsrfTokenValid('import_alumnos', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Token de seguridad inválido. Volvé a intentar.');
            return $this->redirectToRoute('app_alumno_index', [], Response::HTTP_SEE_OTHER);
        }

        $instituto = $this->getUser()->getInstituto();
        if (!$instituto) {
            $this->addFlash('error', 'No se encontró el instituto asociado a tu usuario.');
            return $this->redirectToRoute('app_alumno_index', [], Response::HTTP_SEE_OTHER);
        }

        $file = $request->files->get('file');
        if (!$file instanceof UploadedFile || !$file->isValid()) {
            $this->addFlash('error', 'No se recibió un archivo válido.');
            return $this->redirectToRoute('app_alumno_index', [], Response::HTTP_SEE_OTHER);
        }

        // El nombre original lo controla el cliente: nunca se usa como ruta de destino
        // (permitiría escribir fuera del directorio con "../"). Solo se lee su extensión
        // para validarla y para que PhpSpreadsheet elija el lector correcto.
        $extension = strtolower($file->getClientOriginalExtension() ?: (string) $file->guessExtension());
        if (!in_array($extension, self::EXTENSIONES_PERMITIDAS, true)) {
            $this->addFlash('error', sprintf(
                'Formato no soportado. Se aceptan: %s.',
                implode(', ', self::EXTENSIONES_PERMITIDAS)
            ));
            return $this->redirectToRoute('app_alumno_index', [], Response::HTTP_SEE_OTHER);
        }

        if ($file->getSize() > self::TAMANO_MAXIMO) {
            $this->addFlash('error', 'El archivo supera el tamaño máximo de 10 MB.');
            return $this->redirectToRoute('app_alumno_index', [], Response::HTTP_SEE_OTHER);
        }

        // Fuera de public/ para que la planilla subida no quede accesible por web,
        // y con un nombre generado por el servidor.
        $fileFolder = $this->getParameter('kernel.project_dir') . '/var/tmp/imports';
        $nombreSeguro = bin2hex(random_bytes(16)) . '.' . $extension;

        try {
            $file->move($fileFolder, $nombreSeguro);
        } catch (FileException $e) {
            $this->addFlash('error', 'No se pudo procesar el archivo subido.');
            return $this->redirectToRoute('app_alumno_index', [], Response::HTTP_SEE_OTHER);
        }

        $rutaArchivo = $fileFolder . '/' . $nombreSeguro;

        $totalAgregados = 0;
        $alumnosQueNoGuardadamos = [];
        $em = $doctrine->getManager();

        try {
            $spreadsheet = IOFactory::load($rutaArchivo);
            $spreadsheet->getActiveSheet()->removeRow(1); // Quitar la fila de encabezados
            $sheetData = $spreadsheet->getActiveSheet()->toArray(null, true, true, true);

            foreach ($sheetData as $Row) {
                $nombre = $Row['B'] ?? null;
                $apellido = $Row['C'] ?? null;
                $email = $Row['D'] ?? null;
                $dni = $Row['G'] ?? null;

                if (empty($nombre) || empty($apellido) || empty($email) || empty($dni)) {
                    // Fila incompleta: se informa con lo que haya, sin arrastrar valores
                    // de la fila anterior (antes se usaban variables de la iteración previa).
                    if (array_filter($Row)) {
                        $alumnosQueNoGuardadamos[] = [
                            'nombre' => $nombre,
                            'apellido' => $apellido,
                            'email' => $email,
                            'dni' => $dni,
                        ];
                    }
                    continue;
                }

                $fecha_nac = $Row['E'] ?? null;
                $lugar_nac = $Row['F'] ?? null;
                $fijo = $Row['H'] ?? null;
                $celu = $Row['I'] ?? null;
                $contacto_emergencia = $Row['J'] ?? null;
                $padre_madre_tutor = $Row['K'] ?? null;
                $telefono_padre_madre_tutor = $Row['L'] ?? null;
                $mail_padre_madre_tutor = $Row['M'] ?? null;
                $dni_padre_madre_tutor = $Row['N'] ?? null;
                $escuela = $Row['O'] ?? null;
                $actividades = $Row['P'] ?? null;
                $grupo_sanguineo = $Row['Q'] ?? null;
                $enfermedad = $Row['R'] ?? null;
                $alergia = $Row['S'] ?? null;
                $medicacion = $Row['T'] ?? null;
                $nombreCurso = $Row['U'] ?? null;
                $como_conocio = $Row['V'] ?? null;

                $alumno = new Alumno();
                // Sin esto el flush falla siempre: Alumno.instituto es nullable=false.
                $alumno->setInstituto($instituto);
                $alumno->setNombre($nombre);
                $alumno->setApellido($apellido);
                $alumno->setEmail($email);
                try {
                    $date = new \DateTime($fecha_nac);
                } catch (\Exception $e) {
                    $date = new \DateTime();
                }
                $alumno->setFNac($date);
                $alumno->setDni($dni);
                $alumno->setLNac($lugar_nac);
                $alumno->setTelefonoFijo($fijo);
                $alumno->setCelular($celu);
                $alumno->setContactoEmergencia($contacto_emergencia);
                $alumno->setNTutor($padre_madre_tutor);
                $alumno->setTTutor($telefono_padre_madre_tutor);
                $alumno->setCorreTutor($mail_padre_madre_tutor);
                $alumno->setDniTutor($dni_padre_madre_tutor);
                $alumno->setEscuela($escuela);
                $alumno->setExtras($actividades);
                $alumno->setGSanguineo($grupo_sanguineo);
                $alumno->setEnfermedad($enfermedad);
                $alumno->setAlergico($alergia);
                $alumno->setMedicacion($medicacion);
                $alumno->setComoConociste($como_conocio);
                $alumno->setActivo(1);

                if (!empty($nombreCurso)) {
                    // Filtrado por instituto: sin esto podía asignarse el curso de otro instituto.
                    $curso = $cursoRepository->findOneBy([
                        'nombre' => $nombreCurso,
                        'instituto' => $instituto,
                    ]);
                    if ($curso) {
                        $alumno->addCurso($curso);
                    }
                }

                try {
                    $em->persist($alumno);
                    $em->flush();
                    $totalAgregados++;
                } catch (UniqueConstraintViolationException $e) {
                    // Duplicado (email/DNI ya existente): se informa la fila.
                    $doctrine->resetManager();
                    $em = $doctrine->getManager();
                    $alumnosQueNoGuardadamos[] = [
                        'nombre' => $nombre,
                        'apellido' => $apellido,
                        'email' => $email,
                        'dni' => $dni,
                    ];
                } catch (\Exception $e) {
                    // Cualquier otro error de persistencia: se informa la fila y se sigue.
                    $doctrine->resetManager();
                    $em = $doctrine->getManager();
                    $alumnosQueNoGuardadamos[] = [
                        'nombre' => $nombre,
                        'apellido' => $apellido,
                        'email' => $email,
                        'dni' => $dni,
                    ];
                }
            }
        } catch (\Exception $e) {
            $this->addFlash('error', 'No se pudo leer la planilla. Verificá que el formato sea correcto.');
            return $this->redirectToRoute('app_alumno_index', [], Response::HTTP_SEE_OTHER);
        } finally {
            if (is_file($rutaArchivo)) {
                unlink($rutaArchivo);
            }
        }

        $this->addFlash('success', sprintf('Se importaron %d alumnos.', $totalAgregados));
        if ($alumnosQueNoGuardadamos) {
            $this->addFlash('warning', sprintf('%d filas no se pudieron importar.', count($alumnosQueNoGuardadamos)));
        }

        return $this->redirectToRoute('app_alumno_index', [], Response::HTTP_SEE_OTHER);
    }
}
