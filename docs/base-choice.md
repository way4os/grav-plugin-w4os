# The base of the site (2026-11-15)

The criterion: universality, the lightest system with the easiest install for an ordinary user on an ordinary server, able to do forms, sign-in and plugins with pages as plain `.md` files.

| | Grav 2.2 | Typemill 2 | Datenstrom Yellow 0.9 | Pico | Bludit 3 |
| --- | --- | --- | --- | --- | --- |
| Pages | `.md` + YAML front matter | `.md` | `.md` | `.md` | own text files + JSON |
| Forms | official Form plugin: forms in the front matter, validation, nonce, custom process actions | contact form plugin | contact extension | none | none |
| Sign-in | official Login plugin: sessions, access levels, an event for external authentication (used by `login-ldap`, `login-oauth2`) | core users, a register plugin | editors only (edit extension) | none | administrators only |
| Plugins | large catalogue, maintained by the Grav team | small | a few dozen extensions | stale | medium |
| PHP | 8.3+ | 8+ | 7+ | 7.2+ | 7/8 |
| Footprint | ≈ 31 MB core + 9 MB Form, Login, Email (measured, without admin) | not measured, smaller | very small, not measured | small | small |
| Install | unzip, open in a browser | unzip, setup in the browser | unzip | unzip + composer | unzip, setup in the browser |
| Maintained in 2026 | yes (2.2) | yes (every two months; two login CVEs in 2026) | slowly (last dated release 2024) | no (no stable release since 2020) | slowly |

**Grav.** It is the only one where the site is just a plugin: forms, sessions, access levels, mails, multilingual pages and the hook for an external sign-in all exist and are maintained. Yellow is lighter, but forms, sessions and access would have to be built, which is what we must not do. Typemill is the closest rival, with a smaller ecosystem and a younger login. Grav 2 needs PHP 8.3, which ordinary hosting has in 2026 (8.1 ended in 2025), and it is what the Magìiíc sites already run (2.2.23, same plugins).
