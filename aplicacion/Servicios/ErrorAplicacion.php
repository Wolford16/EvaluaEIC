<?php
declare(strict_types=1);
namespace EvaluaIA\Servicios;
final class ErrorAplicacion extends \RuntimeException {
    public function __construct(
        string $mensaje,
        public readonly int $estado = 422,
        public readonly ?string $codigo = null,
        public readonly ?int $reintentarEn = null,
    ) { parent::__construct($mensaje); }
}
