<?php

use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFilter;
use Twig\TwigFunction;

describe('templates', function () {
    test('every template of the plugin compiles', function () {
        $loader = new FilesystemLoader(dirname(__DIR__).'/templates');
        $twig = new Environment($loader);
        // The functions and filters of Grav and of the plugin: their names only matter here
        $twig->registerUndefinedFunctionCallback(fn (string $name) => new TwigFunction($name, fn () => null));
        $twig->registerUndefinedFilterCallback(fn (string $name) => new TwigFilter($name, fn ($v) => $v));
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__).'/templates', FilesystemIterator::SKIP_DOTS));
        $compiled = 0;
        foreach ($files as $file) {
            if (str_ends_with($file->getFilename(), '.twig')) {
                $twig->compileSource($twig->getLoader()->getSourceContext(substr($file->getPathname(), strlen(dirname(__DIR__).'/templates/'))));
                $compiled++;
            }
        }

        expect($compiled)->toBeGreaterThan(5);
    });
});
