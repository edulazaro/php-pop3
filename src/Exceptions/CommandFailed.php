<?php

namespace EduLazaro\Pop3\Exceptions;

/**
 * The server answered a command with -ERR: a message number that does not exist, a command it
 * does not support (UIDL and TOP are optional in the standard), a mailbox it locked.
 */
class CommandFailed extends Pop3Exception
{
}
