<?php

namespace App\Controller;

use App\Entity\Alumno;
use App\Repository\AlumnoRepository;
use App\Repository\CursoRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\Persistence\ManagerRegistry;
use Exception;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;


/**
 * @Route("/admin/excel")
 */
class ExcelController extends AbstractController
{
    /**
     * @Route("/import/alumnos", name="app_excel_import_alumnos", methods={"POST"})
     */
    public function import(Request $request, CursoRepository $cursoRepository, AlumnoRepository $alumnoRepository, ManagerRegistry $doctrine)
    {
        $file = $request->files->get('file'); // get the file from the sent request

        $fileFolder = __DIR__ . '/../../public/uploads/excels/';  //choose the folder in which the uploaded file will be stored

        $filePathName = md5(uniqid()) . $file->getClientOriginalName();
        // apply md5 function to generate an unique identifier for the file and concat it with the file extension
        try {
            $file->move($fileFolder, $filePathName);
        } catch (FileException $e) {
            dd($e);
        }
        $spreadsheet = IOFactory::load($fileFolder . $filePathName); // Here we are able to read from the excel file
        $row = $spreadsheet->getActiveSheet()->removeRow(1); // I added this to be able to remove the first file line
        $sheetData = $spreadsheet->getActiveSheet()->toArray(null, true, true, true); // here, the read data is turned into an array
        $totalAgregados = 0;
        $alumnosQueNoGuardadamos = [];
        $em = $doctrine->getManager();
        foreach ($sheetData as $Row) {
            if ( !empty($Row['B']) && !empty($Row['C']) && !empty($Row['D']) && !empty($Row['G'])  ) {
                $nombre = $Row['B'];
                $apellido = $Row['C'];
                $email = $Row['D'];
                $dni = $Row['G'];

                $fecha_nac = $Row['E'];
                $lugar_nac = $Row['F'];

                $fijo = $Row['H'];
                $celu = $Row['I'];
                $contacto_emergencia = $Row['J'];
                $padre_madre_tutor = $Row['K'];
                $telefono_padre_madre_tutor = $Row['L'];
                $mail_padre_madre_tutor = $Row['M'];
                $dni_padre_madre_tutor = $Row['N'];
                $escuela = $Row['O'];
                $actividades = $Row['P'];
                $grupo_sanguineo = $Row['Q'];
                $enfermedad = $Row['R'];
                $alergia = $Row['S'];
                $medicacion = $Row['T'];
                $curso = $Row['U'];
                $como_conocio = $Row['V'];

                $alumno = new Alumno();
                $alumno->setNombre($nombre);
                $alumno->setApellido($apellido);
                $alumno->setEmail($email);
                try{
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
                $curso = $cursoRepository->findBy(['nombre' => $curso]);
                if (!empty($curso)) {
                    $alumno->addCurso($curso[0]);
                }

                try {
                    try {
                        $em->beginTransaction();
                        $em->persist($alumno);
                        $em->flush();
                        $em->commit();
                        $totalAgregados ++;
                    } catch (Exception $e) {
                        $em->rollback();
                        $doctrine->resetManager();
                    }
                } catch (UniqueConstraintViolationException $exception) {
                    $alumnosQueNoGuardadamos[] = ['nombre' => $nombre, 'apellido' => $apellido, 'email' => $email, 'dni' => $dni];
                }
            } else {
                $alumnosQueNoGuardadamos[] = ['nombre' => $nombre, 'apellido' => $apellido, 'email' => $email, 'dni' => $dni];
            }

        }

        return $this->redirectToRoute('app_alumno_index', ['totalAgregados' => $totalAgregados, 'alumnosQueNoGuardadamos' => $alumnosQueNoGuardadamos], Response::HTTP_SEE_OTHER);

    }
}
