<?php

describe('the home page of the site (css/w4os.css)', function () {
    test('what the grid is in boxes: its info and its status, as the steps of the Connect block; the message of the day as a text', function () {
        $css = (string) file_get_contents(dirname(__DIR__).'/css/w4os.css');

        expect($css)->toContain('.w4os-home .block-grid-info, .w4os-home .block-grid-status {')
            ->and($css)->not->toContain('.block-motd {');
    });
});
