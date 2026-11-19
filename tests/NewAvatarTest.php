<?php

use OpenSim\Api\Contract;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFilter;
use Twig\TwigFunction;
use W4os\Grav\Forms;
use W4os\Grav\Items;
use W4os\Grav\Site;
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
            ->and($fields['firstName'])->toMatchArray(['type' => 'text', 'label' => 'w4os.first_name', 'help' => 'w4os.first_name_help', 'maxlength' => 32])
            ->and($fields['firstName']['validate'])->toMatchArray(['required' => true, 'pattern' => '[A-Za-z][A-Za-z0-9]*'])
            ->and($fields['email'])->toMatchArray(['type' => 'email'])
            ->and($fields['email'])->not->toHaveKey('validate')
            ->and($fields['password'])->toMatchArray(['type' => 'password', 'validate' => ['required' => true]])
            ->and($fields['model'])->toMatchArray(['type' => 'w4os_models', 'label' => 'w4os.model']);
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
