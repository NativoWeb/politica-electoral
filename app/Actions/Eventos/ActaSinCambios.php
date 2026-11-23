<?php

namespace App\Actions\Eventos;

use RuntimeException;

// Thrown inside EditConfirmedActa's transaction when the correction changes nothing, so whatever it rewrote is rolled
// back and neither the version nor the history move.
class ActaSinCambios extends RuntimeException {}
