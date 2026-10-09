<?php

namespace TheFountainhead\Metis\Exceptions;

use Exception;

/**
 * Thrown when the Criipto/MitID identity provider cannot be reached: a
 * DNS/network failure that outlasted the retries, or the circuit breaker
 * being open.
 */
class CriiptoUnreachableException extends Exception {}
