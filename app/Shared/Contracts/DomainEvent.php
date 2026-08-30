<?php

namespace App\Shared\Contracts;

/**
 * Hoy se despacha como Laravel Event local. Implementar esta interfaz
 * en cada evento de dominio permite migrar a un broker externo (Kafka u otro)
 * más adelante sin cambiar la lógica de negocio que dispara o consume el evento.
 */
interface DomainEvent
{
    public function eventName(): string;

    /**
     * @return array<string, mixed>
     */
    public function payload(): array;
}
