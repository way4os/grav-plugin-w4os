<?php

use W4OS\Engine\Api\Contract;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFilter;
use Twig\TwigFunction;
use W4OS\Grav\Posts;
use W4OS\Views\Contract as OpenApi;
use W4OS\Views\PostsView;

/** A profile of the helpers as its block draws it (templates/components/profile-block.twig): its tabs, its stream with two posts. */
const PROFILE_HTML = '<div class="profile"><section id="profile-inworld" class="profile-section"><h2>In world</h2></section>'
    .'<section id="profile-feed" class="profile-section">'."\n".'<h2>Feed</h2>'."\n"
    .'<article id="profile-post-p1" class="profile-post"><p class="profile-post-text">Hello</p></article>'
    .'<article id="profile-post-p2" class="profile-post"><p class="profile-post-text">Again</p></article>'
    .'</section><section id="profile-web" class="profile-section"><h2>Web</h2></section></div>';

describe('the posts of a profile', function () {
    $view = PostsView::of((new OpenApi(Contract::document()))->postsOf(), 'u1', 'u1');

    test('are written in the field the engine describes, compact: one line that grows, its label as its placeholder, its length at most, required', function () use ($view) {
        expect(Posts::fields($view))->toEqual(['content' => [
            'type' => 'textarea', 'classes' => 'w4os-autogrow w4os-post-field', 'rows' => 1, 'placeholder' => 'w4os.post_label', 'display_label' => false,
            'validate' => ['required' => true, 'max' => 1000],
        ]]);
    });

    test('show the form at the top of the stream, the actions of each post in it, nothing else changed', function () {
        $html = Posts::inFeed(PROFILE_HTML, '<form id="w4os-post"></form>', fn (string $id) => "<b>delete $id</b>");

        expect($html)->toContain("<h2>Feed</h2><form id=\"w4os-post\"></form>\n<article")
            ->and($html)->toContain('<p class="profile-post-text">Hello</p><b>delete p1</b></article>', '<p class="profile-post-text">Again</p><b>delete p2</b></article>')
            ->and(str_replace(['<form id="w4os-post"></form>', '<b>delete p1</b>', '<b>delete p2</b>'], '', $html))->toBe(PROFILE_HTML)
            // A profile without its stream (an error, an avatar unknown) stays as it is
            ->and(Posts::inFeed('<p>Not found</p>', '<form></form>', fn () => 'x'))->toBe('<p>Not found</p>');
    });

    test('have their actions in a small menu each (⋮), delete asked before, with the nonce of the form and the id of the post', function () use ($view) {
        $twig = new Environment(new FilesystemLoader(dirname(__DIR__).'/templates'));
        $twig->addFunction(new TwigFunction('nonce_field', fn (string $action) => "<input type=\"hidden\" name=\"nonce\" value=\"$action\">"));
        $twig->addFilter(new TwigFilter('t', fn ($key) => "[$key]"));
        $html = $twig->render('w4os/post-actions.html.twig', ['view' => $view, 'post' => 'p1']);

        expect($html)->toContain('<details class="w4os-post-menu">', '<summary aria-label="&#x5B;w4os.actions&#x5D;" title="&#x5B;w4os.actions&#x5D;">⋮</summary>', '<form method="post" class="w4os-post-actions">', '<input type="hidden" name="nonce" value="w4os-post">', '<input type="hidden" name="post" value="p1">',
            '<button name="action" value="deletePost" data-w4os-ask="&#x5B;w4os.ask_delete_post&#x5D;" onclick="return confirm(this.dataset.w4osAsk)"', '[w4os.action_delete_post]</button>');
    });
});
