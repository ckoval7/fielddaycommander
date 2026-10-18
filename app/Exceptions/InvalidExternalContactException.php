<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when an external logger sends a contact that fails validation
 * (e.g. a callsign with characters no callsign contains), so the packet is dropped.
 */
class InvalidExternalContactException extends RuntimeException {}
