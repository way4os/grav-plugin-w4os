<?php

namespace W4os\Grav;

use W4OS\Views\PostsView;

/**
 * The stream of a profile for its avatar, with Grav's Form plugin: the field that writes a post (w4os-views PostsView, from the
 * engine's description), and the profile of the helpers with the form at the top of its stream and the actions of each post in it.
 */
final class Posts
{
    /**
     * The fields of the form that writes a post, for Grav's Form plugin: compact, a text of one line that grows as it is written,
     * its label as its placeholder (the Form plugin's display_label: false).
     *
     * @return array<string,array<string,mixed>>
     */
    public static function fields(PostsView $view): array
    {
        $fields = [];
        foreach ($view->fields as $field) {
            $input = Items::input($field);
            $input['type'] === 'textarea' && $input = ['classes' => $input['classes'].' w4os-post-field', 'rows' => 1, 'placeholder' => $input['label'], 'display_label' => false] + $input;
            unset($input['label'], $input['help']);
            $fields[(string) $field['name']] = $input;
        }

        return $fields;
    }

    /**
     * The profile of the helpers with the form at the top of its stream (after its title) and the actions of each post at its end;
     * a profile without a stream as it is.
     *
     * @param \Closure(string $post): string $actions the HTML of the actions of a post, by its id
     */
    public static function inFeed(string $html, string $form, \Closure $actions): string
    {
        if (preg_match('#<section id="profile-feed"[^>]*>\s*<h2>.*?</h2>#s', $html, $match, PREG_OFFSET_CAPTURE) !== 1) {
            return $html;
        }
        $end = $match[0][1] + strlen($match[0][0]);
        $close = strpos($html, '</section>', $end);
        $close === false && $close = strlen($html);
        $feed = (string) preg_replace_callback('#(<article id="profile-post-([^"]+)"[^>]*>.*?)(</article>)#s', fn ($m) => $m[1].$actions(html_entity_decode($m[2], ENT_QUOTES)).$m[3], substr($html, $end, $close - $end));

        return substr($html, 0, $end).$form.$feed.substr($html, $close);
    }
}
