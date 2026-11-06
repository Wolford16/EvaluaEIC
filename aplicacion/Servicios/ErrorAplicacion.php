<?php
declare(strict_types=1);
namespace EvaluaEIC\Servicios;
final class ErrorAplicacion extends \RuntimeException {
    public function __construct(
        string $mensaje,
        public int $estado = 422,
        public ?string $codigo = null,
        public ?int $reintentarEn = null,
    ) { parent::__construct($mensaje); }
}
