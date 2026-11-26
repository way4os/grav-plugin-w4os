<?php

use W4OS\Grav\Site;

describe('a picture of the grid', function () {
    test('is the one of the views (W4OS\\Views\\Picture): its picture; a person without one the engine\'s silhouette, faint; never initials', function () {
        $tag = Site::pictureTag('/helpers/assets/a1.jpg?default=person', '/helpers/assets/none.jpg?default=person', 'w4os-thumb w4os-thumb-person');

        expect($tag)->toBe(W4OS\Views\Picture::tag('/helpers/assets/a1.jpg?default=person', '/helpers/assets/none.jpg?default=person', 'w4os-pic w4os-thumb w4os-thumb-person', 32))
            // No picture: the silhouette, at half its opacity; a place without one, nothing
            ->and(Site::pictureTag(null, '/none.jpg', 'w4os-thumb'))->toContain('class="w4os-picture w4os-pic w4os-thumb w4os-picture-none"', 'src="/none.jpg"', 'style="opacity: 0.5"')
            ->and(Site::pictureTag(null, null, 'w4os-map-thumb', 48))->toBe('')
            ->and(Site::hasPicture('a1b2c3d4-0000-0000-0000-000000000001'))->toBeTrue()
            ->and(Site::hasPicture('00000000-0000-0000-0000-000000000000'))->toBeFalse()
            ->and(Site::hasPicture(null))->toBeFalse()
            ->and(Site::pictureTag('/x.jpg?a=1&b="2"', null, '', 64))->toContain('src="/x.jpg?a=1&amp;b=&quot;2&quot;"', 'width: 64px')
            ->and(method_exists(Site::class, 'initials'))->toBeFalse();
        foreach (['css/w4os.css', 'css/admin.css', 'js/avatar-picker.js', 'w4os.php'] as $file) {
            expect((string) file_get_contents(dirname(__DIR__)."/$file"))->not->toContain('data-initials', 'initials(');
        }
    });

    test('is the one tag of the pictures of the lists and the pages of the items, no bare img of a picture of the grid', function () {
        foreach (['w4os/list-cells', 'w4os/item-parts', 'forms/fields/w4os_identity/w4os_identity', 'forms/fields/w4os_photo/w4os_photo'] as $template) {
            $twig = (string) file_get_contents(dirname(__DIR__)."/templates/$template.html.twig");
            expect($twig)->toContain('w4os_picture_tag(')->not->toContain('src="{{ w4os_picture(');
        }
    });
});
