<?php
// The texts of the plugin that w4os also translates (its .po files, the English text as msgid), whose translation here differs:
// a deliberate fix (then fix the .po of w4os too) or a drift to align. One source of vocabulary for the whole suite.
//
//   php dev/po-check.php [path of the languages folder of w4os, default ../w4os/languages]

require dirname(__DIR__).'/vendor/autoload.php';

use Symfony\Component\Yaml\Yaml;

$folder = rtrim($argv[1] ?? dirname(__DIR__, 2).'/w4os/languages', '/');
$locales = ['fr' => 'fr_FR', 'de' => 'de_DE', 'it' => 'it_IT', 'nl' => 'nl_NL', 'pt-BR' => 'pt_BR', 'cy' => 'cy_GB'];
$en = Yaml::parseFile(dirname(__DIR__).'/languages/en.yaml')['w4os'];
foreach ($locales as $lang => $locale) {
    $file = "$folder/w4os-$locale.po";
    if (! is_file($file) || ! is_file(dirname(__DIR__)."/languages/$lang.yaml")) {
        continue;
    }
    preg_match_all('/^msgid "(.*)"\nmsgstr "(.*)"/m', (string) file_get_contents($file), $m, PREG_SET_ORDER);
    $po = [];
    foreach ($m as $x) {
        $x[2] === '' || $po[stripcslashes($x[1])] = stripcslashes($x[2]);
    }
    $mine = Yaml::parseFile(dirname(__DIR__)."/languages/$lang.yaml")['w4os'];
    foreach ($en as $key => $text) {
        if (isset($po[$text]) && str_replace('’', "'", $po[$text]) !== str_replace('’', "'", (string) ($mine[$key] ?? ''))) {
            echo "$lang $key\n  w4os:   {$po[$text]}\n  plugin: ".($mine[$key] ?? '(missing)')."\n";
        }
    }
}
