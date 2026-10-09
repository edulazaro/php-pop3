<?php

namespace EduLazaro\Pop3\Exceptions;

/**
 * The server could not be reached, the TLS handshake failed (a certificate that does not
 * verify, for one), the server did not greet, or the connection dropped or timed out.
 */
class ConnectionFailed extends Pop3Exception
{
}
