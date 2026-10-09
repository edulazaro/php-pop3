<?php

namespace EduLazaro\Pop3;

use EduLazaro\Pop3\Exceptions\ConnectionFailed;

/**
 * A connection over a socket, plain or TLS.
 *
 * The TLS options are passed with the socket instead of being left to PHP's defaults, so the
 * certificate check is the caller's choice: on by default, off only for a server whose
 * certificate is self-signed or issued to another name. Every read and write has a timeout,
 * so a server that stops answering fails instead of hanging a queue worker.
 */
final class SocketConnection implements Connection
{
    /** @var resource */
    private $stream;

    /**
     * @param  resource  $stream
     * @param  bool  $verifyCertificate
     */
    private function __construct($stream, private bool $verifyCertificate)
    {
        $this->stream = $stream;
    }

    /**
     * @param  string  $host
     * @param  int  $port
     * @param  Encryption  $encryption
     * @param  bool  $verifyCertificate
     * @param  int  $timeout  Seconds, for the connection and for every read.
     * @return self
     *
     * @throws ConnectionFailed
     */
    public static function open(string $host, int $port, Encryption $encryption, bool $verifyCertificate, int $timeout): self
    {
        $context = stream_context_create(['ssl' => self::tlsOptions($host, $verifyCertificate)]);
        $scheme = $encryption === Encryption::Ssl ? 'tls' : 'tcp';

        $stream = @stream_socket_client("{$scheme}://{$host}:{$port}", $errno, $error, $timeout, STREAM_CLIENT_CONNECT, $context);

        if ($stream === false) {
            throw new ConnectionFailed("Could not connect to {$host}:{$port}: ".($error ?: 'unknown error').'.');
        }

        stream_set_timeout($stream, $timeout);

        return new self($stream, $verifyCertificate);
    }

    /**
     * @param  string  $line
     * @return void
     *
     * @throws ConnectionFailed
     */
    public function writeLine(string $line): void
    {
        if (@fwrite($this->stream, $line."\r\n") === false) {
            throw new ConnectionFailed('The connection was lost while sending.');
        }
    }

    /**
     * @return string
     *
     * @throws ConnectionFailed
     */
    public function readLine(): string
    {
        $line = @fgets($this->stream);

        if ($line === false) {
            $meta = stream_get_meta_data($this->stream);

            throw new ConnectionFailed($meta['timed_out'] ? 'The server stopped answering.' : 'The connection was closed by the server.');
        }

        return $line;
    }

    /**
     * @return void
     *
     * @throws ConnectionFailed
     */
    public function enableCrypto(): void
    {
        foreach (self::tlsOptions('', $this->verifyCertificate) as $option => $value) {
            if ($option !== 'peer_name') {
                stream_context_set_option($this->stream, 'ssl', $option, $value);
            }
        }

        if (@stream_socket_enable_crypto($this->stream, true, STREAM_CRYPTO_METHOD_TLS_CLIENT) !== true) {
            throw new ConnectionFailed('The TLS handshake after STLS failed'.($this->verifyCertificate ? ': is the certificate valid for this host?' : '.'));
        }
    }

    /**
     * @return void
     */
    public function close(): void
    {
        if (is_resource($this->stream)) {
            fclose($this->stream);
        }
    }

    /**
     * @param  string  $host
     * @param  bool  $verify
     * @return array<string, mixed>
     */
    private static function tlsOptions(string $host, bool $verify): array
    {
        return array_filter([
            'peer_name' => $host ?: null,
            'verify_peer' => $verify,
            'verify_peer_name' => $verify,
            'allow_self_signed' => ! $verify,
            'SNI_enabled' => true,
        ], fn ($value) => $value !== null);
    }
}
