<?php

namespace W4os\Grav;

/**
 * What the forms of the site ask of the engine: register an avatar, confirm its email, forgotten password, the account of the
 * avatar signed in, the avatars for the managers of the grid. The rules of the names, the passwords and the models are the
 * engine's; only what the form itself adds (the password typed twice, the terms) is checked here.
 * Plain PHP: a refusal is a FormError that carries the key of its text in the language files, from the code of the engine's problem.
 */
final class Forms
{
    /** The profile fields an avatar edits on the site. */
    public const PROFILE = ['about', 'wants', 'skills', 'languages', 'firstLife', 'profileUrl'];

    /** The check boxes of a profile: lists of the names of their flags (the engine's Avatar::WANTS and Avatar::SKILLS). */
    public const PROFILE_LISTS = ['wantsList', 'skillsList'];

    /** The codes of the problems of the engine (Problem::CODES), each told by the text `w4os.error_<code>` (- as _). */
    public const CODES = [
        'not-found', 'invalid-request', 'invalid-document', 'type-conflict', 'unauthorized', 'method-not-allowed', 'channel-unavailable',
        'instance-failed', 'internal-error', 'sign-in-refused', 'account-disabled', 'account-banned', 'email-unconfirmed', 'name-invalid',
        'name-reserved', 'name-taken', 'password-required', 'password-invalid', 'email-invalid', 'email-required', 'model-unknown', 'link-invalid',
        'field-unknown', 'token-refused', 'no-confirmation-pending', 'mail-failed', 'role-unknown', 'role-level-conflict', 'filter-unknown',
        'filter-value-unknown', 'sort-unknown', 'field-read-only', 'flag-unknown', 'owner-unknown', 'estate-shared',
        'config-not-writable', 'instance-user-required', 'restore-conflict', 'source-not-readable', 'post-invalid',
    ];

    /**
     * @param array<string,mixed> $config the settings of the plugin
     * @param \Closure(string $type): string $link the absolute address of a page of the plugin (register, confirm, reset...)
     */
    public function __construct(private Engine $engine, private array $config, private \Closure $link) {}

    /** Whether the engine mails the links; off on a test site, where the links of a registration are shown instead. */
    public function sends(): bool
    {
        return (bool) ($this->config['send_mail'] ?? true);
    }

    /**
     * Register an avatar: First and last name, email, password (twice), model.
     *
     * @param array<string,mixed> $data the fields of the form
     * @return array{avatar: array<string,mixed>, confirm: bool, sent: bool, link: ?string, notice: ?array<string,mixed>} the
     *     avatar, whether it must confirm its email, whether the mail left, the link of the confirmation when it was not mailed
     *     (test site), what the engine says it did (its notice)
     */
    public function register(array $data): array
    {
        $this->samePassword($data);
        $confirm = (bool) ($this->config['register']['confirm_email'] ?? true);
        $attributes = [
            'firstName' => trim((string) ($data['first_name'] ?? '')),
            'lastName' => trim((string) ($data['last_name'] ?? '')),
            'email' => trim((string) ($data['email'] ?? '')),
            'password' => (string) ($data['password'] ?? ''),
            'model' => (string) ($data['model'] ?? ''),
        ];
        if ($attributes['firstName'] === '' || $attributes['lastName'] === '' || $attributes['email'] === '' || $attributes['password'] === '') {
            throw new FormError('w4os.error_required');
        }
        $attributes += $confirm ? ['confirmEmail' => true, 'link' => $this->tokenLink('confirm')] + ($this->sends() ? [] : ['send' => false]) : ['active' => true];
        [$avatar, $meta] = $this->ask(fn () => $this->engine->createAvatar($attributes));

        return [
            'avatar' => $avatar,
            'confirm' => $confirm,
            'sent' => ($meta['emailConfirmation'] ?? '') === 'sent',
            'link' => $meta['confirmation']['link'] ?? null,
            'notice' => is_array($meta['notice'] ?? null) && isset($meta['notice']['code']) ? $meta['notice'] : null,
        ];
    }

    /**
     * An avatar a manager makes (the form of its creation, Contract::creationOf()): what the form was given only, active at once, as
     * w4os-wp makes it from its admin; no mail to confirm.
     *
     * @param array<string,mixed> $attributes firstName, lastName, email, password, model
     * @return array<string,mixed> the avatar made
     */
    public function create(array $attributes): array
    {
        $given = array_filter(array_map(fn ($value) => is_string($value) ? trim($value) : $value, $attributes), fn ($value) => $value !== null && $value !== '');
        isset($attributes['password']) && $given['password'] = (string) $attributes['password'];
        [$avatar] = $this->ask(fn () => $this->engine->createAvatar($given + ['active' => true]));

        return $avatar;
    }

    /** @return array<string,mixed> the avatar, now active, or with its new email */
    public function confirm(string $token): array
    {
        return $this->ask(fn () => $this->engine->confirmEmail(trim($token)));
    }

    /**
     * Mail a new confirmation link to an avatar that waits for it (by name).
     *
     * @return array{link: ?string, notice: ?array<string,mixed>} the link, when it is not mailed (test site); what the engine says
     *     it did, when it is
     */
    public function resendConfirmation(string $name): array
    {
        $given = $this->ask(fn () => $this->engine->requestEmailConfirmation(trim($name), $this->tokenLink('confirm'), $this->sends()));

        return ['link' => $given['link'] ?? null, 'notice' => $given['notice'] ?? null];
    }

    /**
     * Mail a link to choose a new password. The answer is the same whether the name or the email is someone's or not; on a test
     * site nothing is mailed and the link is not shown (it opens the account of anyone): the API gives the token.
     *
     * @return ?array<string,mixed> what the engine says it did (its notice), null when it says nothing
     */
    public function forgot(array $data): ?array
    {
        $login = trim((string) ($data['login'] ?? ''));
        if ($login === '') {
            throw new FormError('w4os.error_required');
        }
        try {
            return $this->engine->requestPasswordReset($login, $this->tokenLink('reset'), $this->sends());
        } catch (EngineException $e) {
            // Nobody has this name or email: said only when nothing is mailed, the visitor is not told
            if ($e->problemCode !== 'not-found') {
                throw $this->error($e);
            }

            return null;
        }
    }

    /** @return array<string,mixed> the avatar whose password changed */
    public function reset(array $data): array
    {
        $this->samePassword($data);

        return $this->ask(fn () => $this->engine->resetPassword(trim((string) ($data['token'] ?? '')), (string) $data['password']));
    }

    /**
     * Sign an avatar in.
     *
     * @return array<string,mixed> the avatar
     */
    public function signIn(string $login, string $password): array
    {
        return $this->ask(fn () => $this->engine->signIn($login, $password));
    }

    /** @return array<string,mixed> the avatar */
    public function avatar(string $uuid): array
    {
        return $this->ask(fn () => $this->engine->avatar($uuid));
    }

    /**
     * The names of the boxes checked, sorted: none for nothing (the boxes of a form are posted only when checked).
     *
     * @return list<string>
     */
    public static function checked(mixed $list): array
    {
        return \W4OS\Views\FormView::checked($list);
    }

    /**
     * The email and the profile of the avatar signed in: what changed only. A new email changes once the avatar clicks the link
     * mailed to it.
     *
     * @param array<string,mixed> $current the avatar as the engine has it
     * @param bool $confirm whether a new email waits for its confirmation (the avatar's own form), else it changes at once (a manager)
     * @param array<string,mixed> $extra more to change in the same call (the role a manager gives)
     * @return array{avatar: array<string,mixed>, email: ?string, link: ?string} the avatar, the new email waiting for its
     *     confirmation, its link when it was not mailed (test site)
     */
    public function profile(array $current, array $data, bool $confirm = true, array $extra = []): array
    {
        $attributes = $extra;
        // The languages as the viewer keeps them: "Français, English"
        isset($data['languages']) && ($data['languages'] = implode(', ', array_filter(array_map('trim', preg_split('/[,;]/u', (string) $data['languages']) ?: []))));
        foreach (self::PROFILE as $key) {
            if (array_key_exists($key, $data) && trim((string) $data[$key]) !== trim((string) ($current[$key] ?? ''))) {
                $attributes[$key] = trim((string) $data[$key]);
            }
        }
        foreach (self::PROFILE_LISTS as $key) {
            if (array_key_exists($key, $data) && ($list = self::checked($data[$key])) !== self::checked($current[$key] ?? [])) {
                $attributes[$key] = $list;
            }
        }
        // Shown in search (the profile is public)
        if (array_key_exists('published', $data) && (bool) $data['published'] !== (bool) ($current['published'] ?? false)) {
            $attributes['published'] = (bool) $data['published'];
        }
        $email = trim((string) ($data['email'] ?? ($current['email'] ?? '')));
        // An avatar's own profile has an email; a manager may leave it empty or clear it (the models and the technical accounts have none)
        $email === '' && $confirm && throw new FormError('w4os.error_required');
        $changed = strtolower($email) !== strtolower(trim((string) ($current['email'] ?? '')));
        if ($changed) {
            $attributes += $confirm
                ? ['email' => $email, 'confirmEmail' => true, 'link' => $this->tokenLink('confirm', ['change' => 1])] + ($this->sends() ? [] : ['send' => false])
                : ['email' => $email];
        }
        if ($attributes === []) {
            return ['avatar' => $current, 'email' => null, 'link' => null];
        }
        [$avatar, $meta] = $this->ask(fn () => $this->engine->updateAvatar((string) $current['uuid'], $attributes));

        return ['avatar' => $avatar, 'email' => $changed && $confirm ? $email : null, 'link' => $meta['confirmation']['link'] ?? null];
    }

    /**
     * What a manager changes of an avatar: its profile and its email (at once), and its role when another one is picked (a level
     * that is not the one of a role stays until then; the engine refuses a role it does not know).
     *
     * @param array<string,mixed> $edited the avatar as the engine has it
     */
    public function edit(array $edited, array $data): array
    {
        $role = (string) ($data['role'] ?? '');
        $extra = $role !== '' && $role !== ($edited['role'] ?? null) ? ['role' => $role] : [];
        // A new password when the manager gives one, confirmed; the engine checks it as at registration
        if ((string) ($data['password'] ?? '') !== '') {
            $this->samePassword($data);
            $extra['password'] = (string) $data['password'];
        }

        return $this->profile($edited, $data, false, $extra);
    }

    /** A new password for the avatar signed in, once its current one is checked. */
    public function password(array $avatar, array $data): void
    {
        $this->samePassword($data);
        try {
            $this->engine->signIn((string) $avatar['name'], (string) ($data['current'] ?? ''));
        } catch (EngineException $e) {
            throw $e->problemCode === 'sign-in-refused' ? new FormError('w4os.error_current_password', previous: $e) : $this->error($e);
        }
        $this->ask(fn () => $this->engine->updateAvatar((string) $avatar['uuid'], ['password' => (string) $data['password']]));
    }

    /**
     * What the site adds to a request of the engine that mails a link with a token: the page that takes it, and whether the
     * engine sends the mail.
     *
     * @param string $type the page (reset, confirm)
     * @return array{link: string, send?: false}
     */
    public function tokenAttributes(string $type): array
    {
        return ['link' => $this->tokenLink($type)] + ($this->sends() ? [] : ['send' => false]);
    }

    /**
     * The address of a page that takes a token, `{token}` where the engine puts it.
     *
     * @param array<string,scalar> $query more of the address
     */
    private function tokenLink(string $type, array $query = []): string
    {
        $url = ($this->link)($type);

        return $url.(str_contains($url, '?') ? '&' : '?').($query === [] ? '' : http_build_query($query).'&').'token={token}';
    }

    private function samePassword(array $data): void
    {
        if ((string) ($data['password'] ?? '') === '') {
            throw new FormError('w4os.error_required');
        }
        if ((string) $data['password'] !== (string) ($data['password_confirm'] ?? '')) {
            throw new FormError('w4os.error_password_match');
        }
    }

    /** A call to the engine, its problems as form errors. */
    private function ask(\Closure $call): mixed
    {
        try {
            return $call();
        } catch (EngineException $e) {
            throw $this->error($e);
        }
    }

    /** The text of a problem, by its code; what the engine says (English) goes with it, for the log. */
    public function error(EngineException $e): FormError
    {
        $code = in_array($e->problemCode, self::CODES, true) ? $e->problemCode : ($e->status >= 500 ? 'internal-error' : 'invalid-request');

        return new FormError('w4os.error_'.str_replace('-', '_', $code), $e->getMessage(), $e);
    }
}
