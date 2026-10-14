<?php

namespace App\Imports;

use RuntimeException;

/** A guest import file that cannot be imported; the message is shown to the host as written. */
class GuestImportFileException extends RuntimeException {}
