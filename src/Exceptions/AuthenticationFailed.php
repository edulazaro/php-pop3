<?php

namespace EduLazaro\Pop3\Exceptions;

/**
 * The server refused the user or the password. The message carries the server's own words,
 * never the password.
 */
class AuthenticationFailed extends Pop3Exception
{
}
