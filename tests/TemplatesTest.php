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

    test('the menu of a row links to the address the row gives (its profile page), and to its edit page by its UUID', function () {
        $twig = new Environment(new FilesystemLoader(dirname(__DIR__).'/templates'));
        $twig->registerUndefinedFunctionCallback(fn (string $name) => new TwigFunction($name, fn () => null));
        $twig->addFilter(new TwigFilter('t', fn ($key) => $key));
        $menu = $twig->createTemplate("{% import 'w4os/list-cells.html.twig' as cells %}{{ cells.menu(list, item, links) }}");
        $links = [['key' => 'action_view', 'attribute' => 'profilePage'], ['key' => 'action_edit', 'url' => '/edit?avatar={uuid}']];
        $render = fn (array $item) => $menu->render(['list' => ['actions' => []], 'item' => $item, 'links' => $links]);
        $ada = $render(['uuid' => 'u1', 'name' => 'Ada  Love', 'profilePage' => 'https://grid.example.org/profile/Ada.Love']);

        expect($ada)->toContain('href="https&#x3A;&#x2F;&#x2F;grid.example.org&#x2F;profile&#x2F;Ada.Love"', 'href="&#x2F;edit&#x3F;avatar&#x3D;u1"')
            ->and($render(['uuid' => 'u2', 'name' => 'Model Avatar']))->not->toContain('w4os.action_view')->toContain('w4os.action_edit');
    });

    test('an action the engine asks to confirm (ask) is confirmed before it is sent, in the language of the page', function () {
        $twig = new Environment(new FilesystemLoader(dirname(__DIR__).'/templates'));
        $twig->registerUndefinedFunctionCallback(fn (string $name) => new TwigFunction($name, fn () => $name === 'w4os_list_actions' ? [
            ['name' => 'stop', 'label' => 'action_stop', 'ask' => 'ask_stop_instance'],
            ['name' => 'start', 'label' => 'action_start'],
        ] : ''));
        $twig->addFilter(new TwigFilter('t', fn ($key) => "[$key]"));
        $html = $twig->createTemplate("{% import 'w4os/list-cells.html.twig' as cells %}{{ cells.menu(list, item, []) }}")
            ->render(['list' => ['actions' => []], 'item' => ['id' => 'h:9040', 'name' => 'Test Solo']]);

        expect($html)->toContain('<button name="action" value="stop" data-w4os-ask="&#x5B;w4os.ask_stop_instance&#x5D;" onclick="return confirm(this.dataset.w4osAsk)">')
            ->and($html)->toContain('<button name="action" value="start">');
    });
});
