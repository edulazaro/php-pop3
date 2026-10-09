<?php

namespace EduLazaro\Pop3\Tests;

use EduLazaro\Pop3\Client;
use EduLazaro\Pop3\Exceptions\AuthenticationFailed;
use EduLazaro\Pop3\Exceptions\CommandFailed;
use EduLazaro\Pop3\Exceptions\ConnectionFailed;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class ClientTest extends TestCase
{
    public function test_it_logs_in_lists_and_reads_without_deleting(): void
    {
        $server = new ScriptedConnection([
            '+OK POP3 ready',
            '+OK', '+OK logged in',
            '+OK 2 messages', '1 uid-a', '2 uid-b', '.',
            '+OK 120 octets', 'Subject: Gala', '', 'Hello', '.',
            '+OK bye',
        ]);

        $pop = new Client($server);
        $pop->login('info@example.com', 'secret');

        $this->assertSame([1 => 'uid-a', 2 => 'uid-b'], $pop->uids());
        $this->assertSame("Subject: Gala\r\n\r\nHello\r\n", $pop->retrieve(2));

        $pop->quit();

        $this->assertSame(['USER info@example.com', 'PASS secret', 'UIDL', 'RETR 2', 'QUIT'], $server->sent);
        $this->assertTrue($server->closed);
        $this->assertEmpty(array_filter($server->sent, fn ($command) => str_starts_with($command, 'DELE')));
    }

    public function test_a_line_that_starts_with_a_dot_comes_back_single(): void
    {
        $pop = new Client(new ScriptedConnection(['+OK', '+OK', '..hidden line', '...two dots', 'plain', '.']));

        $this->assertSame(".hidden line\r\n..two dots\r\nplain\r\n", $pop->retrieve(1));
    }

    public function test_headers_status_and_sizes(): void
    {
        $server = new ScriptedConnection([
            '+OK',
            '+OK', 'Message-ID: <a@b>', '.',
            '+OK 2 320',
            '+OK', '1 120', '2 200', '.',
        ]);
        $pop = new Client($server);

        $this->assertSame("Message-ID: <a@b>\r\n", $pop->top(1));
        $this->assertSame(['count' => 2, 'size' => 320], $pop->status());
        $this->assertSame([1 => 120, 2 => 200], $pop->sizes());
        $this->assertSame(['TOP 1 0', 'STAT', 'LIST'], $server->sent);
    }

    public function test_a_refused_password_says_so_without_repeating_it(): void
    {
        $pop = new Client(new ScriptedConnection(['+OK', '+OK', '-ERR invalid credentials']));

        try {
            $pop->login('info@example.com', 'secret');
            $this->fail('No exception.');
        } catch (AuthenticationFailed $e) {
            $this->assertSame('The server answered: -ERR invalid credentials', $e->getMessage());
            $this->assertStringNotContainsString('secret', $e->getMessage());
        }
    }

    public function test_a_line_break_in_the_credentials_cannot_inject_a_command(): void
    {
        $server = new ScriptedConnection(['+OK']);
        $pop = new Client($server);

        $this->expectException(InvalidArgumentException::class);

        try {
            $pop->login("info\r\nDELE 1", 'secret');
        } finally {
            $this->assertSame([], $server->sent);
        }
    }

    public function test_an_error_answer_or_a_bad_greeting_fails_clearly(): void
    {
        $pop = new Client(new ScriptedConnection(['+OK', '-ERR no such message']));

        try {
            $pop->retrieve(9);
            $this->fail('No exception.');
        } catch (CommandFailed $e) {
            $this->assertStringContainsString('no such message', $e->getMessage());
        }

        $this->expectException(ConnectionFailed::class);
        new Client(new ScriptedConnection(['-ERR busy']));
    }

    public function test_starttls_upgrades_before_the_password(): void
    {
        $server = new ScriptedConnection(['+OK', '+OK begin TLS', '+OK', '+OK']);
        $pop = new Client($server);

        $pop->startTls();
        $pop->login('info@example.com', 'secret');

        $this->assertTrue($server->crypto);
        $this->assertSame(['STLS', 'USER info@example.com', 'PASS secret'], $server->sent);
    }

    public function test_quit_twice_and_a_dropped_server_do_not_throw(): void
    {
        $server = new ScriptedConnection(['+OK']);
        $pop = new Client($server);

        $pop->quit();
        $pop->quit();

        $this->assertSame(['QUIT'], $server->sent);
        $this->assertTrue($server->closed);

        $this->expectException(ConnectionFailed::class);
        $pop->uids();
    }
}
