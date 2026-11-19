<?php

namespace App\Services\Configuracion;

use RuntimeException;

// A change of the Centro de configuración that its rules do not allow; the message is shown to the superadmin.
class ConfiguracionException extends RuntimeException {}
