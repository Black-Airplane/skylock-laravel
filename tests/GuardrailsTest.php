<?php

/*
 * Promises the package makes about itself, held against every file in src/
 * rather than against one code path: it never runs a command, talks to
 * SkyLock from one class and to nothing else, writes one file, and never
 * reads an exception's text to send it anywhere.
 *
 * A new file or a new call that breaks one fails here, so doing it has to be
 * a decision someone made and reviewed, not something that slipped in.
 */

/**
 * @return array<string, list<PhpToken>> every source file's tokens, by path from src/
 */
function sourceTokens(): array
{
    $files = [];
    $src = dirname(__DIR__).'/src';
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->getExtension() === 'php') {
            $tokens = PhpToken::tokenize((string) file_get_contents($file->getPathname()));
            $files[substr($file->getPathname(), strlen($src) + 1)] = array_values(array_filter($tokens, fn (PhpToken $token): bool => ! $token->isIgnorable()));
        }
    }
    ksort($files);

    return $files;
}

/**
 * Which files call a function (`exec(`) or a method (`->getMessage(`), by name.
 *
 * @param  list<string>  $names
 * @return array<string, list<string>> the names found, by file
 */
function filesCalling(array $names, bool $methods = false): array
{
    $found = [];
    foreach (sourceTokens() as $path => $tokens) {
        foreach ($tokens as $i => $token) {
            if (! $token->is([T_STRING, T_NAME_FULLY_QUALIFIED, T_NAME_QUALIFIED]) || ($tokens[$i + 1] ?? null)?->text !== '(') {
                continue;
            }

            $previous = $tokens[$i - 1] ?? null;
            $isMethod = $previous?->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON]) ?? false;
            $name = strtolower(ltrim($token->text, '\\'));
            if ($isMethod === $methods && ! $previous?->is([T_FUNCTION, T_NEW]) && in_array($name, array_map(strtolower(...), $names), true)) {
                $found[$path][] = $name;
            }
        }
    }

    return array_map(fn (array $names): array => array_values(array_unique($names)), $found);
}

/**
 * Which files name a class or namespace (in a `use`, or in full).
 *
 * @return list<string>
 */
function filesNaming(string ...$fragments): array
{
    $found = [];
    foreach (sourceTokens() as $path => $tokens) {
        foreach ($tokens as $token) {
            if ($token->is([T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED]) && str_contains($token->text, '\\') && array_filter($fragments, fn (string $fragment): bool => str_contains($token->text, $fragment)) !== []) {
                $found[] = $path;
            }
        }
    }

    return array_values(array_unique($found));
}

it('reads every file in src', function () {
    expect(array_keys(sourceTokens()))->toContain('SkyLockClient.php', 'Stack.php', 'Commands/ReportCommand.php')
        // The scan itself works: these are really there.
        ->and(filesCalling(['file_put_contents']))->toHaveKey('LocalState.php')
        ->and(filesCalling(['getMessage'], methods: true))->toHaveKey('HealthChecks.php');
});

it('never runs a command or evaluates code, anywhere', function () {
    expect(filesCalling(['exec', 'shell_exec', 'system', 'passthru', 'proc_open', 'popen', 'pcntl_exec', 'unserialize', 'create_function', 'assert', 'call_user_func', 'call_user_func_array', 'extract', 'putenv', 'mail']))->toBe([])
        ->and(filesNaming('Facades\\Process', 'Symfony\\Component\\Process', 'Illuminate\\Process'))->toBe([]);

    foreach (sourceTokens() as $path => $tokens) {
        foreach ($tokens as $token) {
            expect($token->is([T_EVAL, T_INCLUDE, T_INCLUDE_ONCE, T_REQUIRE, T_REQUIRE_ONCE]) || $token->text === '`')->toBeFalse("{$path} line {$token->line}: {$token->text}");
        }
    }
});

it('talks to SkyLock from one class, and to nothing else', function () {
    expect(filesNaming('Facades\\Http', 'Http\\Client', 'GuzzleHttp', 'Psr\\Http'))->toBe(['SkyLockClient.php'])
        ->and(filesCalling(['curl_init', 'curl_exec', 'curl_multi_exec', 'fsockopen', 'pfsockopen', 'stream_socket_client', 'socket_create', 'fopen', 'gethostbyname', 'gethostbyaddr', 'gethostname', 'dns_get_record', 'php_uname']))->toBe([]);
});

it('reads files in two places and writes one file', function () {
    expect(array_keys(filesCalling(['file_get_contents', 'file', 'readfile', 'parse_ini_file', 'scandir', 'glob'])))->toBe(['LocalState.php', 'Stack.php'])
        ->and(array_keys(filesCalling(['file_put_contents', 'fwrite', 'rename', 'unlink', 'mkdir', 'touch', 'chmod', 'copy', 'symlink', 'tempnam'])))->toBe(['LocalState.php']);
});

it('reads the environment only to recognise a platform or a deploy, and config only through config()', function () {
    expect(array_keys(filesCalling(['getenv'])))->toBe(['Stack.php'])
        ->and(filesCalling(['env']))->toBe([]);

    foreach (sourceTokens() as $path => $tokens) {
        foreach ($tokens as $token) {
            expect($token->is(T_VARIABLE) && in_array($token->text, ['$_ENV', '$_SERVER', '$_GET', '$_POST', '$_COOKIE', '$_REQUEST', '$_FILES', '$GLOBALS'], true))->toBeFalse("{$path} line {$token->line}: {$token->text}");
        }
    }
});

it('never takes an exception’s text or trace, except to tell a missing table apart', function () {
    expect(filesCalling(['getMessage', 'getTrace', 'getTraceAsString', 'getPrevious', '__toString', 'getFile', 'getLine', 'getSql', 'getRawSql', 'getBindings'], methods: true))
        ->toBe(['HealthChecks.php' => ['getmessage']]);
});
