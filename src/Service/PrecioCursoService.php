<?php

namespace App\Service;

use App\Entity\AlumnoCursoHistorico;
use App\Entity\Curso;
use App\Entity\DeudaAlumno;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Aplica el precio actual de un curso a las cuotas impagas de sus alumnos.
 *
 * El precio mensual se copia a la inscripción cuando el alumno se inscribe, y el cálculo de
 * las cuotas lee de esa copia. Por eso, cambiar el precio del curso no afectaba a los ya
 * inscriptos: seguían pagando el precio con el que entraron para siempre, y el instituto no
 * tenía forma de aplicar un aumento. La ficha del alumno ya mostraba el desfasaje
 * ("$40.000 (Ahora: $45.000)") pero nada actuaba sobre él.
 *
 * La regla del negocio es que el precio es el del día que se paga: si una cuota quedó impaga y
 * el precio subió, se cobra al precio nuevo. Lo ya pagado no se toca.
 *
 * No hay precios negociados por alumno que se puedan pisar: setPrecioMensual() solo se llama
 * con el precio del curso, y los descuentos van por otro lado (descuentos promocionales, al
 * momento del cobro).
 */
class PrecioCursoService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private DeudaCalculatorService $deudaCalculator
    ) {
    }

    /**
     * Inscripciones activas cuyo precio quedó desfasado respecto del precio actual del curso.
     *
     * @return AlumnoCursoHistorico[]
     */
    public function inscripcionesDesfasadas(Curso $curso): array
    {
        $precioActual = (float) $curso->getPrecio();

        return array_values(array_filter(
            $this->entityManager->getRepository(AlumnoCursoHistorico::class)
                ->findBy(['curso' => $curso, 'activo' => true]),
            static function (AlumnoCursoHistorico $historico) use ($precioActual) {
                $precio = $historico->getPrecioMensual();

                return $precio !== null && abs((float) $precio - $precioActual) > 0.001;
            }
        ));
    }

    /**
     * Qué pasaría si se aplicara el precio actual, sin escribir nada.
     *
     * @return array{alumnos: int, cuotas: int, inscriptos: int, precioAnterior: float|null, precioActual: float}
     */
    public function previsualizar(Curso $curso): array
    {
        $desfasadas = $this->inscripcionesDesfasadas($curso);

        // Todas las inscripciones activas, no solo las desfasadas: la opción de aplicar el
        // precio se ofrece siempre que haya inscriptos, para poder cambiar el precio y
        // aplicarlo en el mismo guardado. Si solo apareciera cuando ya hay desfasaje, habría
        // que entrar a editar el curso dos veces.
        $inscriptos = count($this->entityManager->getRepository(AlumnoCursoHistorico::class)
            ->findBy(['curso' => $curso, 'activo' => true]));

        $cuotas = 0;
        $precioAnterior = null;
        foreach ($desfasadas as $historico) {
            $precioAnterior ??= (float) $historico->getPrecioMensual();
            $cuotas += count($this->cuotasImpagas($historico));
        }

        return [
            'alumnos' => count($desfasadas),
            'cuotas' => $cuotas,
            'inscriptos' => $inscriptos,
            'precioAnterior' => $precioAnterior,
            'precioActual' => (float) $curso->getPrecio(),
        ];
    }

    /**
     * Aplica el precio actual del curso: actualiza la copia de cada inscripción activa y
     * refresca el importe de las cuotas impagas ya registradas en tabla.
     *
     * Lo segundo hace falta porque esas filas guardan el importe congelado y son las que leen
     * los recordatorios por email y la pantalla de deudas: sin refrescarlas, el mail seguiría
     * pidiendo el precio viejo.
     *
     * @return array{alumnos: int, cuotas: int, precioActual: float}
     */
    public function aplicar(Curso $curso): array
    {
        $precioActual = (float) $curso->getPrecio();
        $instituto = $curso->getInstituto();

        $alumnos = 0;
        $cuotas = 0;

        foreach ($this->inscripcionesDesfasadas($curso) as $historico) {
            foreach ($this->cuotasImpagas($historico) as $deuda) {
                $deuda->setMonto($precioActual);
                // El recargo por mora es un porcentaje del precio, así que se recalcula con la
                // misma regla que usa el cálculo on-demand para mostrarlo.
                $deuda->setInteres($this->deudaCalculator->calcularInteresParaMes(
                    $instituto,
                    $precioActual,
                    (int) $deuda->getMes(),
                    (int) $deuda->getAno()
                ));
                $cuotas++;
            }

            $historico->setPrecioMensual($precioActual);
            $alumnos++;
        }

        $this->entityManager->flush();

        return [
            'alumnos' => $alumnos,
            'cuotas' => $cuotas,
            'precioActual' => $precioActual,
        ];
    }

    /**
     * Cuotas de esta inscripción que todavía no recibieron ni un peso.
     *
     * Se exige que no tengan ninguna aplicación en lugar de comparar importes: por política del
     * sistema cualquier pago cierra la cuota, aunque sea por menos de lo que valía, así que una
     * cuota con aplicaciones ya está saldada y no se toca.
     *
     * @return DeudaAlumno[]
     */
    private function cuotasImpagas(AlumnoCursoHistorico $historico): array
    {
        return $this->entityManager->createQueryBuilder()
            ->select('d')
            ->from(DeudaAlumno::class, 'd')
            ->leftJoin('d.aplicaciones', 'pa')
            ->andWhere('d.alumno = :alumno')
            ->andWhere('d.curso = :curso')
            ->andWhere('d.esCuotaInscripcionAnual = false')
            ->groupBy('d.id')
            ->having('COUNT(pa.id) = 0')
            ->setParameter('alumno', $historico->getAlumno())
            ->setParameter('curso', $historico->getCurso())
            ->getQuery()
            ->getResult();
    }
}
