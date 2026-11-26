<?php

use W4OS\Grav\EngineException;
use W4OS\Grav\Failure;

describe('a failure', function () {
    test('says its status code and its standard reason phrase, never translated, then what was asked', function () {
        expect(Failure::line(Failure::status(500), 'helpers: search'))->toBe('500 Internal Server Error · helpers: search')
            ->and(Failure::status(401))->toBe('401 Unauthorized')
            ->and(Failure::status(502))->toBe('502 Bad Gateway')
            ->and(Failure::status(599))->toBe('599')
            ->and(Failure::line('503 Service Unavailable', ''))->toBe('503 Service Unavailable');
    });

    test('without an HTTP answer, the short reason of the transport', function () {
        expect(Failure::transport('Failed to connect to 127.0.0.1 port 20290 after 0 ms: Could not connect to server'))->toBe('Connection refused')
            ->and(Failure::transport('Operation timed out after 30001 milliseconds'))->toBe('Timeout')
            ->and(Failure::transport('Idle timeout reached'))->toBe('Timeout')
            ->and(Failure::transport('Could not resolve host: grid.example.org'))->toBe('Could not resolve host')
            ->and(Failure::transport('something else'))->toBe('No answer');
    });

    test('of the engine: its status when it answered, the transport when it did not', function () {
        $unreachable = new EngineException(503, 'The engine API cannot be reached: Connection refused', previous: new RuntimeException('Failed to connect: Connection refused'));

        expect(Failure::of(new EngineException(500, 'Error 500')))->toBe('500 Internal Server Error')
            ->and(Failure::of($unreachable))->toBe('Connection refused')
            ->and(Failure::of(new W4OS\Grav\FormError('w4os.error_internal_error', previous: new EngineException(502, 'no JSON'))))->toBe('502 Bad Gateway');
    });

    test('is a problem of what was asked only when the engine answered it with its code: those keep their text', function () {
        expect((new EngineException(409, 'taken', problem(409, 'taken', 'name-taken')))->answered())->toBeTrue()
            ->and((new EngineException(401, 'refused', problem(401, 'refused', 'sign-in-refused')))->answered())->toBeTrue()
            ->and((new EngineException(401, 'Error 401'))->answered())->toBeFalse()
            ->and((new EngineException(401, 'key', problem(401, 'key', 'unauthorized')))->answered())->toBeFalse()
            ->and((new EngineException(500, 'boom', problem(500, 'boom', 'internal-error')))->answered())->toBeFalse();
    });

    test('is said one way on every page: the forms, the lists, the items, the settings, the tasks, the actions, the diagnostics, the blocks', function () {
        $plugin = (string) file_get_contents(dirname(__DIR__).'/w4os.php');

        expect($plugin)->toContain(
            "if (\$engine instanceof EngineException && ! \$engine->answered()) {\n            \$line = Failure::line(Failure::of(\$engine), \$asked === '' ? 'engine' : \"engine: \$asked\");",
            "'error' => \$this->failureText(\$e, \"settings: \$group\")",
            "\$this->failureText(\$e, \"tasks: \$id\")",
            "'error' => \$this->failureText(\$e, \$type)",
            "fn (\\Throwable \$e) => \$this->failureText(\$e, 'health'));",
        )
            ->and(substr_count($plugin, '->getMessage(), \'error\')'))->toBe(0)
            ->and((string) file_get_contents(dirname(__DIR__).'/classes/Blocks.php'))->toContain("Failure::line((string) \$reason, 'helpers: '.implode(', ', \$names))");
        // No sentence of a failure left in the catalog: the status says it
        foreach (glob(dirname(__DIR__).'/i18n/*.yaml') as $file) {
            expect((string) file_get_contents($file))->not->toContain('admin_blocks_unavailable')->not->toContain('admin_blocks_http');
        }
    });
});
