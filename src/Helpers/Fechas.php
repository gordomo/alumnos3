<?php

namespace App\Helpers;

class Fechas {

    public static function getDiaDeLaSemana($dia) {
        switch ($dia) {
            case 1:
                $key = 'Lunes';
                break;
            case 2:
                $key = 'Martes';
                break;
            case 3:
                $key = 'Miercoles';
                break;
            case 4:
                $key = 'Jueves';
                break;
            case 5:
                $key = 'Viernes';
                break;
            case 6:
                $key = 'Sábado';
                break;
            case 0:
                $key = 'Domingo';
                break;
        }

        return $key;
    }

}