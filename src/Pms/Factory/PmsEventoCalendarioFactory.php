<?php
// src/Pms/Factory/PmsEventoFactory.php

namespace App\Pms\Factory;

use App\Pms\Entity\PmsEventoCalendario;

class PmsEventoCalendarioFactory
{
    public function crearInstanciaPorDefecto(): PmsEventoCalendario
    {
        $evento = new PmsEventoCalendario();

        // Aquí defines tu lógica de negocio centralizada
        $evento->setInicio(new \DateTime('tomorrow 14:00'));
        $evento->setFin(new \DateTime('tomorrow +2 days 10:00'));
        $evento->setCantidadAdultos(2);
        $evento->setMonto('0.00');
        $evento->setComision('0.00');

        return $evento;
    }
}