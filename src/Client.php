<?php

namespace EduLazaro\Pop3;

use EduLazaro\Pop3\Exceptions\AuthenticationFailed;
use EduLazaro\Pop3\Exceptions\CommandFailed;
use EduLazaro\Pop3\Exceptions\ConnectionFailed;
use InvalidArgumentException;

/**
 * A POP3 client (RFC 1939; STLS and CAPA from RFC 2449/2595; SASL PLAIN and XOAUTH2 from
 * RFC 5034).
 *
 * It lists the messages with their unique ids, reads a message's headers or the whole of it,
 * and deletes only when asked. POP3 keeps no read or unread mark on the server, so reading
 * changes nothing. Messages come back raw, as the server sent them; parse them with whatever
 * you like (`webklex/php-imap`: `Message::fromString($raw)`).
 *
 * Deleting follows the standard: `delete()` marks, `reset()` unmarks, and the server applies
 * the marks only when the session ends with `quit()`. A session that ends any other way (an
 * exception, an object going out of scope) closes WITHOUT quitting, so nothing marked is
 * deleted by accident. Some servers delete on any disconnect all the same (GreenMail does,
 * against RFC 1939 section 6): to change your mind, call `reset()`, never rely on dropping.
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

    /** What followed the greeting's +OK: holds the APOP timestamp, when the server offers it. */
    private string $greeting;

    /** @var array<int, true> Message numbers marked for deletion in this session. */
    private array $marked = [];

    /**
     * @param  Connection  $connection  A connection whose greeting has not been read yet.
     */
    public function __construct(private Connection $connection)
    {
        $this->greeting = $this->expectOk($this->connection->readLine(), ConnectionFailed::class);
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
        $this->refuseLineBreaks($user, $password);

        $this->send("USER {$user}");
        $this->expectOk($this->connection->readLine(), AuthenticationFailed::class);

        $this->send("PASS {$password}");
        $this->expectOk($this->connection->readLine(), AuthenticationFailed::class);
    }

    /**
     * APOP: proves the password without sending it, with an MD5 of the greeting's timestamp and
     * the secret. Only for servers that offer it (their greeting carries `<…@…>`).
     *
     * @param  string  $user
     * @param  string  $secret
     * @return void
     *
     * @throws AuthenticationFailed When refused, or when the server does not offer APOP.
     */
    public function loginApop(string $user, string $secret): void
    {
        $this->refuseLineBreaks($user, $secret);

        if (! preg_match('/<[^<>]+@[^<>]+>/', $this->greeting, $timestamp)) {
            throw new AuthenticationFailed('The server does not offer APOP: its greeting has no timestamp.');
        }

        $this->send("APOP {$user} ".md5($timestamp[0].$secret));
        $this->expectOk($this->connection->readLine(), AuthenticationFailed::class);
    }

    /**
     * AUTH XOAUTH2: signs in with an OAuth access token instead of a password, as Microsoft 365
     * and Gmail require. Getting the token (client credentials, a refresh token) is the
     * caller's; this only presents it.
     *
     * @param  string  $user  The mailbox address.
     * @param  string  $accessToken
     * @return void
     *
     * @throws AuthenticationFailed With the server's reason when it gives one.
     */
    public function loginOAuth(string $user, string $accessToken): void
    {
        $this->refuseLineBreaks($user, $accessToken);
        $this->sasl('XOAUTH2', base64_encode("user={$user}\x01auth=Bearer {$accessToken}\x01\x01"));
    }

    /**
     * AUTH PLAIN: user and password in one SASL message. Over TLS it is as safe as USER/PASS;
     * some servers accept only this.
     *
     * @param  string  $user
     * @param  string  $password
     * @return void
     *
     * @throws AuthenticationFailed
     */
    public function loginPlain(string $user, string $password): void
    {
        $this->refuseLineBreaks($user, $password);
        $this->sasl('PLAIN', base64_encode("\0{$user}\0{$password}"));
    }

    /**
     * What the server says it supports (CAPA): `TOP`, `UIDL`, `STLS`, `SASL PLAIN XOAUTH2`…
     * An empty list when the server does not answer CAPA, which is optional too.
     *
     * @return list<string>
     */
    public function capabilities(): array
    {
        try {
            $this->command('CAPA');
        } catch (CommandFailed) {
            return [];
        }

        return array_values(array_filter(array_map('trim', preg_split('/\r\n|\n/', $this->readMultiline()))));
    }

    /**
     * Keeps the session alive (NOOP), for a reader that pauses between messages.
     *
     * @return void
     *
     * @throws CommandFailed
     */
    public function noop(): void
    {
        $this->command('NOOP');
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
     * One message's size in bytes (LIST n).
     *
     * @param  int  $number
     * @return int
     *
     * @throws CommandFailed When there is no such message.
     */
    public function size(int $number): int
    {
        return (int) ($this->pairs($this->command("LIST {$number}"))[$number] ?? 0);
    }

    /**
     * One message's unique id (UIDL n).
     *
     * @param  int  $number
     * @return string
     *
     * @throws CommandFailed When there is no such message, or the server does not support UIDL.
     */
    public function uid(int $number): string
    {
        return $this->pairs($this->command("UIDL {$number}"))[$number] ?? '';
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
     * Marks a message for deletion (DELE). Nothing is deleted until `quit()`; until then the
     * message cannot be read in this session and `reset()` unmarks it.
     *
     * @param  int  $number
     * @return void
     *
     * @throws CommandFailed When there is no such message, or it is already marked.
     */
    public function delete(int $number): void
    {
        $this->command("DELE {$number}");
        $this->marked[$number] = true;
    }

    /**
     * Unmarks every message marked in this session (RSET).
     *
     * @return void
     *
     * @throws CommandFailed
     */
    public function reset(): void
    {
        $this->command('RSET');
        $this->marked = [];
    }

    /**
     * The message numbers marked for deletion in this session.
     *
     * @return list<int>
     */
    public function marked(): array
    {
        return array_keys($this->marked);
    }

    /**
     * Ends the session (QUIT): the server deletes what was marked, and the connection closes.
     * Safe to call twice.
     *
     * @return void
     */
    public function quit(): void
    {
        $this->close(true);
    }

    /**
     * A client dropped without quit() says goodbye only when nothing is marked: with marks
     * pending it closes without QUIT, so they are not applied by accident.
     */
    public function __destruct()
    {
        $this->close($this->marked === []);
    }

    /**
     * @param  bool  $quit  Whether to send QUIT, which applies the deletions.
     * @return void
     */
    private function close(bool $quit): void
    {
        if (! $this->open) {
            return;
        }

        $this->open = false;

        try {
            if ($quit) {
                $this->connection->writeLine('QUIT');
                $this->connection->readLine();
                $this->marked = [];
            }
        } catch (ConnectionFailed) {
        } finally {
            $this->connection->close();
        }
    }

    /**
     * A SASL exchange with an initial response. When the server sends a challenge instead of
     * an answer, it is an error report (XOAUTH2 sends its reason so): an empty line ends the
     * exchange and the server's reason goes into the exception.
     *
     * @param  string  $mechanism
     * @param  string  $initialResponse  Base64.
     * @return void
     *
     * @throws AuthenticationFailed
     */
    private function sasl(string $mechanism, string $initialResponse): void
    {
        $this->send("AUTH {$mechanism} {$initialResponse}");
        $answer = $this->connection->readLine();

        if (str_starts_with($answer, '+ ') || rtrim($answer, "\r\n") === '+') {
            $reason = base64_decode(trim(substr($answer, 1)), true) ?: trim(substr($answer, 1));
            $this->connection->writeLine('');
            $this->connection->readLine();

            throw new AuthenticationFailed('The server refused the '.$mechanism.' sign-in'.($reason !== '' ? ': '.$reason : '.'));
        }

        $this->expectOk($answer, AuthenticationFailed::class);
    }

    /**
     * A value with a line break would let it inject commands of its own: refused before anything
     * is sent.
     *
     * @param  string  ...$values
     * @return void
     */
    private function refuseLineBreaks(string ...$values): void
    {
        foreach ($values as $value) {
            if (preg_match('/[\r\n]/', $value)) {
                throw new InvalidArgumentException('A user, password or token cannot contain a line break.');
            }
        }
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
