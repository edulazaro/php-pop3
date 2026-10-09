<?php

namespace EduLazaro\Pop3\Exceptions;

use RuntimeException;

/**
 * Anything that went wrong talking to a POP3 server. Catch this one to handle them all.
 */
class Pop3Exception extends RuntimeException
{
}
