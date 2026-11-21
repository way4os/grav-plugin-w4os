<?php

use W4OS\Engine\Api\Contract;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFilter;
use Twig\TwigFunction;
use W4OS\Grav\Forms;
use W4OS\Grav\Items;
use W4OS\Grav\Site;
use W4OS\Views\Contract as OpenApi;
use W4OS\Views\FormView;

describe('the page that makes an avatar', function () {
    test('lives under Avatars in the admin', function () {
        expect(Site::adminRoutes(['admin' => '/w4os'], [])['new'])->toBe('/w4os/avatars/new')
            ->and(Site::ADMIN_SECTIONS['new'])->toBe('manage');
    });

    test('has the fields of the creation of w4os-wp, as the engine describes them: the names checked as the viewers take them, the models as pictures', function () {
        $form = (new OpenApi(Contract::document()))->creationOf('avatars');
        $fields = Items::fields(FormView::of($form, [], null, true, 200));

        expect(array_keys($fields))->toBe(['id', 'firstName', 'lastName', 'email', 'password', 'model'])
            ->and($fields['firstName'])->toMatchArray(['type' => 'text', 'label' => 'w4os.first_name', 'description' => 'w4os.first_name_help', 'maxlength' => 32])
            ->and($fields['firstName']['validate'])->toMatchArray(['required' => true, 'pattern' => '[A-Za-z][A-Za-z0-9]*'])
            ->and($fields['email'])->toMatchArray(['type' => 'email'])
            ->and($fields['email'])->not->toHaveKey('validate')
            ->and($fields['password'])->toMatchArray(['type' => 'password', 'validate' => ['required' => true]])
            ->and($fields['model'])->toMatchArray(['type' => 'w4os_models', 'label' => 'w4os.model'])
            // Its help is the field's own, under its title
            ->and($fields['model'])->not->toHaveKey('help');
    });

    test('makes an avatar that can sign in at once, with what the form was given only', function () {
        $engine = fakeEngine([[201, avatarDocument(['uuid' => 'u9', 'name' => 'Nova Star'])]], $log, grid: 'demo');
        $avatar = (new Forms($engine, [], fn () => ''))->create(['firstName' => 'Nova', 'lastName' => 'Star', 'email' => '', 'password' => 'secret1', 'model' => 'Default Ruth']);

        expect($avatar['uuid'])->toBe('u9')
            ->and($log[0]['method'])->toBe('POST')
            ->and($log[0]['body']['data']['attributes'])->toBe(['firstName' => 'Nova', 'lastName' => 'Star', 'password' => 'secret1', 'model' => 'Default Ruth', 'active' => true]);
    });

    test('is opened from the list of the avatars by its button, for whom may make one', function () {
        $twig = new Environment(new FilesystemLoader(dirname(__DIR__).'/templates'));
        $twig->addFunction(new TwigFunction('w4os_create', fn (string $type) => $type === 'avatars' ? ['url' => '/w4os/avatars/new', 'label' => 'action_new_avatar'] : null));
        $twig->addFilter(new TwigFilter('t', fn ($key) => "[$key]"));
        $html = $twig->render('w4os/manage-header.html.twig', ['page' => new class {
            public function title(): string { return 'Avatars'; }
        }]);

        expect($html)->toContain('Avatars', 'href="&#x2F;w4os&#x2F;avatars&#x2F;new"', '[w4os.action_new_avatar]');
    });
});

describe('the registration', function () {
    test('takes the names as the viewers do, as w4os-wp checks them: a letter, then letters and numbers, made plain as they are typed', function () {
        $page = (string) file_get_contents(dirname(__DIR__).'/pages/register.md');
        $fields = Symfony\Component\Yaml\Yaml::parse(explode('---', $page)[1])['form']['fields'];
        $twig = new Environment(new Twig\Loader\ChainLoader([new Twig\Loader\ArrayLoader(['forms/form.html.twig' => '<form></form>']), new FilesystemLoader(dirname(__DIR__).'/templates')]));
        $twig->registerUndefinedFunctionCallback(fn (string $name) => new TwigFunction($name, fn () => ''));
        $twig->addFilter(new TwigFilter('t', fn ($key) => "[$key]"));
        $assets = new class {
            public array $js = [];
            public function addJs(string $url, array $options = []): void { $this->js[] = $url; }
        };
        $twig->render('w4os/register.html.twig', ['assets' => $assets, 'config' => ['plugins' => ['login' => []]]]);

        foreach (['first_name', 'last_name'] as $name) {
            expect($fields[$name])->toMatchArray(['maxlength' => 32, 'help' => "w4os.{$name}_help"])
                ->and($fields[$name]['validate'])->toMatchArray(['required' => true, 'pattern' => '[A-Za-z][A-Za-z0-9]*']);
        }
        expect($assets->js)->toContain('plugin://w4os/js/avatar-name.js');
    });
});
