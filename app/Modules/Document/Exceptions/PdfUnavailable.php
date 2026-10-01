<?php

namespace App\Modules\Document\Exceptions;

use RuntimeException;

/**
 * The PDF could not be made (Gotenberg is down, not configured or refused the page).
 */
class PdfUnavailable extends RuntimeException {}
