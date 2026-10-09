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

    public function test_deleting_marks_and_only_quit_applies_it(): void
    {
        $server = new ScriptedConnection(['+OK', '+OK marked', '+OK marked', '+OK reset', '+OK marked', '+OK bye']);
        $pop = new Client($server);

        $pop->delete(1);
        $pop->delete(2);
        $this->assertSame([1, 2], $pop->marked());

        $pop->reset();
        $this->assertSame([], $pop->marked());

        $pop->delete(2);
        $pop->quit();

        $this->assertSame(['DELE 1', 'DELE 2', 'RSET', 'DELE 2', 'QUIT'], $server->sent);
        $this->assertSame([], $pop->marked());
    }

    public function test_marks_pending_when_the_client_is_dropped_are_not_applied(): void
    {
        $server = new ScriptedConnection(['+OK', '+OK marked']);
        $pop = new Client($server);
        $pop->delete(1);

        unset($pop);

        $this->assertSame(['DELE 1'], $server->sent);
        $this->assertTrue($server->closed);
    }

    public function test_a_dropped_client_without_marks_still_says_goodbye(): void
    {
        $server = new ScriptedConnection(['+OK', '+OK bye']);
        $pop = new Client($server);

        unset($pop);

        $this->assertSame(['QUIT'], $server->sent);
    }

    public function test_apop_proves_the_secret_without_sending_it(): void
    {
        $server = new ScriptedConnection(['+OK POP3 ready <1896.697170952@dbc.mtview.ca.us>', '+OK maildrop has 1 message']);
        $pop = new Client($server);

        $pop->loginApop('mrose', 'tanstaaf');

        $this->assertSame(['APOP mrose c4c9334bac560ecc979e58001b3e22fb'], $server->sent);
        $this->assertStringNotContainsString('tanstaaf', implode("\n", $server->sent));
    }

    public function test_apop_is_refused_by_a_server_without_a_timestamp(): void
    {
        $server = new ScriptedConnection(['+OK POP3 ready']);
        $pop = new Client($server);

        $this->expectException(\EduLazaro\Pop3\Exceptions\AuthenticationFailed::class);

        try {
            $pop->loginApop('mrose', 'tanstaaf');
        } finally {
            $this->assertSame([], $server->sent);
        }
    }

    public function test_oauth_and_plain_sign_in(): void
    {
        $server = new ScriptedConnection(['+OK', '+OK signed in']);
        $pop = new Client($server);
        $pop->loginOAuth('info@example.com', 'token-123');

        $this->assertSame('AUTH XOAUTH2 '.base64_encode("user=info@example.com\x01auth=Bearer token-123\x01\x01"), $server->sent[0]);

        $server = new ScriptedConnection(['+OK', '+OK']);
        (new Client($server))->loginPlain('info@example.com', 'secret');

        $this->assertSame('AUTH PLAIN '.base64_encode("\0info@example.com\0secret"), $server->sent[0]);
    }

    public function test_a_refused_token_reports_the_servers_reason(): void
    {
        $reason = '{"status":"401","schemes":"bearer","scope":"https://outlook.office365.com/.default"}';
        $server = new ScriptedConnection(['+OK', '+ '.base64_encode($reason), '-ERR authentication failed']);
        $pop = new Client($server);

        try {
            $pop->loginOAuth('info@example.com', 'expired');
            $this->fail('No exception.');
        } catch (\EduLazaro\Pop3\Exceptions\AuthenticationFailed $e) {
            $this->assertStringContainsString('"status":"401"', $e->getMessage());
            $this->assertStringNotContainsString('expired', $e->getMessage());
        }

        $this->assertSame('', $server->sent[1]);
    }

    public function test_capabilities_noop_and_one_message(): void
    {
        $server = new ScriptedConnection([
            '+OK',
            '+OK capability list follows', 'TOP', 'UIDL', 'SASL PLAIN XOAUTH2', 'STLS', '.',
            '+OK',
            '+OK 2 200',
            '+OK 2 uid-b',
        ]);
        $pop = new Client($server);

        $this->assertSame(['TOP', 'UIDL', 'SASL PLAIN XOAUTH2', 'STLS'], $pop->capabilities());
        $pop->noop();
        $this->assertSame(200, $pop->size(2));
        $this->assertSame('uid-b', $pop->uid(2));
        $this->assertSame(['CAPA', 'NOOP', 'LIST 2', 'UIDL 2'], $server->sent);

        $this->assertSame([], (new Client(new ScriptedConnection(['+OK', '-ERR unknown command'])))->capabilities());
    }
}
