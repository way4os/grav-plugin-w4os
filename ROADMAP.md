# Roadmap

## Grav 1.7

The plugin runs on Grav 1.7 (PHP 8.2 and 8.3) for the servers without PHP 8.3, Debian 12 first (its long-term support ends on
2028-06-30). The dates of the README:

- [ ] 2027-01-01: Grav 1.7 on PHP 8.2 no longer supported (PHP 8.2's end of life, 2026-12-31); PHP 8.3 still is
- [ ] during 2027: how many sites still run Grav 1.7 (the w4os sites known, the issues, the downloads); none: deprecated, said in the
      README and the changelog, before December 2027
- [ ] 2027-12-31 at the latest: Grav 1.7 no longer supported (PHP 8.3's end of life; Grav 1.7 runs on no newer PHP)
    - [ ] the blueprint asks Grav 2 again (`grav >= 2.0.0`), composer.json PHP 8.3
    - [ ] what is there for Grav 1.7 alone goes: the feature checks (`Dates::canTellRelative()` in w4os-views, `I18n::ago()` in
          the helpers), the rule of Twig 1.44's syntax for the templates (the helpers' `tests/Environment/TwigTest.php`), the
          tests on a Grav 1.7 (`GRAV_DIR`)
    - [ ] w4os-site: no more `site-grav17` (Grav 1.7 and Quark 2 1.1.12 for PHP older than 8.3), its package needs PHP 8.3
