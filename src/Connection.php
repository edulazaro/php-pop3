<?php

namespace EduLazaro\Pop3;

/**
 * The line-based transport under the client. The client speaks the protocol; a connection
 * only moves lines, so tests can script a server without opening a socket.
 */
interface Connection
{
    /**
     * Sends one line; the connection adds the CRLF.
     *
     * @param  string  $line
     * @return void
     */
    public function writeLine(string $line): void;

    /**
     * The next line from the server, with its line ending.
     *
     * @return string
     */
    public function readLine(): string;

    /**
     * Turns TLS on over the connection already open, for STLS.
     *
     * @return void
     */
    public function enableCrypto(): void;

    /**
     * @return void
     */
    public function close(): void;
}
