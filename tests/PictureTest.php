<?php

use W4OS\Grav\Site;

describe('a picture of the grid', function () {
    test('is never broken and never under initials: the picture when it has one, else its initials, the picture taken away when it cannot be loaded', function () {
        $tag = Site::pictureTag('/helpers/assets/a1.jpg?default=person', 'AL', 'w4os-thumb w4os-thumb-person');

        expect($tag)->toBe('<span class="w4os-pic w4os-pic-shown w4os-thumb w4os-thumb-person" style="width: 32px; height: 32px" data-initials="AL"><img src="/helpers/assets/a1.jpg?default=person" alt="" width="32" height="32" onerror="this.parentNode.classList.remove(\'w4os-pic-shown\');this.remove()"></span>')
            // No picture: the initials alone, never a picture of nothing with them
            ->and(Site::pictureTag(null, 'AL', 'w4os-thumb'))->toBe('<span class="w4os-pic w4os-thumb" style="width: 32px; height: 32px" data-initials="AL"></span>')
            ->and(Site::hasPicture('a1b2c3d4-0000-0000-0000-000000000001'))->toBeTrue()
            ->and(Site::hasPicture('00000000-0000-0000-0000-000000000000'))->toBeFalse()
            ->and(Site::hasPicture(null))->toBeFalse()
            ->and(Site::pictureTag('/x.jpg?a=1&b="2"', '<B>', '', 64))->toContain('src="/x.jpg?a=1&amp;b=&quot;2&quot;"', 'data-initials="&lt;B&gt;"', 'width: 64px')
            ->and(Site::initials('Ada Love'))->toBe('AL')
            ->and(Site::initials('Bart.Simpson'))->toBe('BS')
            ->and(Site::initials('plaza'))->toBe('P')
            ->and(Site::initials('Élodie de la Tour'))->toBe('ÉD')
            ->and(Site::initials(null))->toBe('');
        foreach (['css/w4os.css', 'css/admin.css'] as $css) {
            expect((string) file_get_contents(dirname(__DIR__)."/$css"))->toContain('.w4os-pic::before { content: attr(data-initials);', '.w4os-pic-shown::before { content: none; }');
        }
    });

    test('is the one tag of the pictures of the lists and the pages of the items, no bare img of a picture of the grid', function () {
        foreach (['list-cells', 'item-parts'] as $template) {
            $twig = (string) file_get_contents(dirname(__DIR__)."/templates/w4os/$template.html.twig");
            expect($twig)->toContain('w4os_picture_tag(')->not->toContain('src="{{ w4os_picture(');
        }
    });
});
