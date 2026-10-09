<?php

namespace EduLazaro\Pop3;

use EduLazaro\Pop3\Exceptions\AuthenticationFailed;
use EduLazaro\Pop3\Exceptions\CommandFailed;
use EduLazaro\Pop3\Exceptions\ConnectionFailed;
use InvalidArgumentException;

/**
 * A read-only POP3 client (RFC 1939, STLS from RFC 2595).
 *
 * It lists the messages with their unique ids, reads a message's headers or the whole of it,
 * and leaves. It never deletes: there is no DELE here, so the mailbox stays as its owners see
 * it, and POP3 keeps no read or unread mark on the server for it to change. Messages come back
 * raw, as the server sent them; parse them with whatever you like (`webklex/php-imap`:
 * `Message::fromString($raw)`).
 *
 *     $pop = Client::connect('pop.example.com');
 *     $pop->login('info@example.com', 'secret');
 *
 *     foreach ($pop->uids() as $number => $uid) {
 *         $raw = $pop->retrieve($number);
 *     }
 *
 *     $pop->quit();
 *
 * Message numbers belong to one session (1, 2, 3… as the server lists them); unique ids
 * (UIDL) survive between sessions, which is what tells a new message from one already read.
 */
final class Client
{
    private bool $open = true;

    /**
     * @param  Connection  $connection  A connection whose greeting has not been read yet.
     */
    public function __construct(private Connection $connection)
    {
        $this->expectOk($this->connection->readLine(), ConnectionFailed::class);
    }

    /**
     * Connects and reads the greeting; with `StartTls`, upgrades before returning, so the
     * password never travels in clear.
     *
     * @param  string  $host
     * @param  int|null  $port  The encryption's usual port when null (995 for SSL, 110 otherwise).
     * @param  Encryption  $encryption
     * @param  bool  $verifyCertificate  Off only for a self-signed certificate or one issued to another name.
     * @param  int  $timeout  Seconds, for the connection and for every answer.
     * @return self
     *
     * @throws ConnectionFailed
     */
    public static function connect(string $host, ?int $port = null, Encryption $encryption = Encryption::Ssl, bool $verifyCertificate = true, int $timeout = 30): self
    {
        $client = new self(SocketConnection::open($host, $port ?? $encryption->defaultPort(), $encryption, $verifyCertificate, $timeout));

        if ($encryption === Encryption::StartTls) {
            $client->startTls();
        }

        return $client;
    }

    /**
     * Upgrades a plain connection to TLS (STLS).
     *
     * @return void
     *
     * @throws CommandFailed When the server does not offer STLS.
     * @throws ConnectionFailed When the handshake fails.
     */
    public function startTls(): void
    {
        $this->command('STLS');
        $this->connection->enableCrypto();
    }

    /**
     * USER and PASS. A user or password with a line break is refused before anything is sent:
     * it would let the value inject commands of its own.
     *
     * @param  string  $user
     * @param  string  $password
     * @return void
     *
     * @throws AuthenticationFailed
     */
    public function login(string $user, string $password): void
    {
        foreach ([$user, $password] as $value) {
            if (preg_match('/[\r\n]/', $value)) {
                throw new InvalidArgumentException('A user or password cannot contain a line break.');
            }
        }

        $this->send("USER {$user}");
        $this->expectOk($this->connection->readLine(), AuthenticationFailed::class);

        $this->send("PASS {$password}");
        $this->expectOk($this->connection->readLine(), AuthenticationFailed::class);
    }

    /**
     * How many messages there are and their total size in bytes (STAT).
     *
     * @return array{count: int, size: int}
     *
     * @throws CommandFailed
     */
    public function status(): array
    {
        [$count, $size] = array_map('intval', array_pad(explode(' ', $this->command('STAT')), 2, 0));

        return ['count' => $count, 'size' => $size];
    }

    /**
     * Every message's size in bytes, by message number (LIST).
     *
     * @return array<int, int>
     *
     * @throws CommandFailed
     */
    public function sizes(): array
    {
        $this->command('LIST');

        return array_map('intval', $this->pairs($this->readMultiline()));
    }

    /**
     * Every message's unique id, by message number (UIDL). The id stays the same across
     * sessions, so it is what to remember to read only what is new.
     *
     * @return array<int, string>
     *
     * @throws CommandFailed When the server does not support UIDL (it is optional).
     */
    public function uids(): array
    {
        $this->command('UIDL');

        return $this->pairs($this->readMultiline());
    }

    /**
     * A whole message, raw (RETR). The message stays on the server.
     *
     * @param  int  $number
     * @return string
     *
     * @throws CommandFailed When there is no such message.
     */
    public function retrieve(int $number): string
    {
        $this->command("RETR {$number}");

        return $this->readMultiline();
    }

    /**
     * A message's headers, and its first `$lines` lines of body (TOP): enough to read its
     * Message-ID or its sender before downloading the rest.
     *
     * @param  int  $number
     * @param  int  $lines
     * @return string
     *
     * @throws CommandFailed When there is no such message, or the server does not support TOP.
     */
    public function top(int $number, int $lines = 0): string
    {
        $this->command("TOP {$number} {$lines}");

        return $this->readMultiline();
    }

    /**
     * Ends the session (QUIT) and closes the connection. Safe to call twice.
     *
     * @return void
     */
    public function quit(): void
    {
        if (! $this->open) {
            return;
        }

        $this->open = false;

        try {
            $this->connection->writeLine('QUIT');
            $this->connection->readLine();
        } catch (ConnectionFailed) {
        } finally {
            $this->connection->close();
        }
    }

    /**
     * A client dropped without quit() still says goodbye.
     */
    public function __destruct()
    {
        $this->quit();
    }

    /**
     * Sends a command and returns the text after its +OK.
     *
     * @param  string  $command
     * @return string
     *
     * @throws CommandFailed
     */
    private function command(string $command): string
    {
        $this->send($command);

        return $this->expectOk($this->connection->readLine(), CommandFailed::class);
    }

    /**
     * @param  string  $line
     * @return void
     *
     * @throws ConnectionFailed
     */
    private function send(string $line): void
    {
        if (! $this->open) {
            throw new ConnectionFailed('The session is closed.');
        }

        $this->connection->writeLine($line);
    }

    /**
     * The text after +OK, or the exception of the given class with the server's -ERR words.
     *
     * @param  string  $line
     * @param  class-string<\EduLazaro\Pop3\Exceptions\Pop3Exception>  $failure
     * @return string
     */
    private function expectOk(string $line, string $failure): string
    {
        $line = rtrim($line, "\r\n");

        if (str_starts_with($line, '+OK')) {
            return ltrim(substr($line, 3));
        }

        throw new $failure('The server answered: '.($line === '' ? 'nothing' : $line));
    }

    /**
     * A multi-line answer up to the line with a single dot, undoing the dot-stuffing: a line
     * of the message that starts with a dot comes doubled, so it is not taken for the end.
     * Line endings are kept as the server sent them.
     *
     * @return string
     */
    private function readMultiline(): string
    {
        $body = '';

        while (true) {
            $line = $this->connection->readLine();

            if (rtrim($line, "\r\n") === '.') {
                return $body;
            }

            $body .= str_starts_with($line, '..') ? substr($line, 1) : $line;
        }
    }

    /**
     * "1 abc" lines into [1 => 'abc'].
     *
     * @param  string  $lines
     * @return array<int, string>
     */
    private function pairs(string $lines): array
    {
        $pairs = [];

        foreach (preg_split('/\r\n|\n/', trim($lines)) as $line) {
            if (preg_match('/^(\d+)\s+(\S+)/', $line, $match)) {
                $pairs[(int) $match[1]] = $match[2];
            }
        }

        return $pairs;
    }
}
