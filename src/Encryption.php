<?php

namespace EduLazaro\Pop3;

/**
 * How the connection is protected. `Ssl` is TLS from the first byte, usually on port 995;
 * `StartTls` connects in clear on port 110 and upgrades with STLS before anything is sent;
 * `None` sends the password in clear and is meant only for a test server.
 */
enum Encryption: string
{
    case Ssl = 'ssl';
    case StartTls = 'starttls';
    case None = 'none';

    /**
     * The port a server usually listens on for it.
     *
     * @return int
     */
    public function defaultPort(): int
    {
        return $this === self::Ssl ? 995 : 110;
    }
}
