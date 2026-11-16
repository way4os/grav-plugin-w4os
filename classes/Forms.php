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
        'instance-failed', 'internal-error', 'sign-in-refused', 'account-disabled', 'email-unconfirmed', 'name-invalid', 'name-reserved',
        'name-taken', 'password-required', 'password-invalid', 'email-invalid', 'email-required', 'model-unknown', 'link-invalid',
        'field-unknown', 'token-refused', 'no-confirmation-pending', 'mail-failed', 'role-unknown', 'role-level-conflict',
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
     * @return array{avatar: array<string,mixed>, confirm: bool, sent: bool, link: ?string} the avatar, whether it must confirm
     *     its email, whether the mail left, the link of the confirmation when it was not mailed (test site)
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
        ];
    }

    /** @return array<string,mixed> the avatar, now active, or with its new email */
    public function confirm(string $token): array
    {
        return $this->ask(fn () => $this->engine->confirmEmail(trim($token)));
    }

    /**
     * Mail a new confirmation link to an avatar that waits for it (by name).
     *
     * @return ?string the link, when it is not mailed (test site)
     */
    public function resendConfirmation(string $name): ?string
    {
        $given = $this->ask(fn () => $this->engine->requestEmailConfirmation(trim($name), $this->tokenLink('confirm'), $this->sends()));

        return $given['link'] ?? null;
    }

    /**
     * Mail a link to choose a new password. The answer is the same whether the name or the email is someone's or not; on a test
     * site nothing is mailed and the link is not shown (it opens the account of anyone): the API gives the token.
     */
    public function forgot(array $data): void
    {
        $login = trim((string) ($data['login'] ?? ''));
        if ($login === '') {
            throw new FormError('w4os.error_required');
        }
        try {
            $this->engine->requestPasswordReset($login, $this->tokenLink('reset'), $this->sends());
        } catch (EngineException $e) {
            // Nobody has this name or email: said only when nothing is mailed, the visitor is not told
            if ($e->problemCode !== 'not-found') {
                throw $this->error($e);
            }
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
        $names = array_values(array_unique(array_filter(array_map('strval', (array) $list), fn (string $name) => $name !== '')));
        sort($names);

        return $names;
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
        $email = trim((string) ($data['email'] ?? ($current['email'] ?? '')));
        // An avatar's own profile has an email; a manager may leave it empty (the models and the technical accounts have none)
        if ($email === '') {
            $confirm && throw new FormError('w4os.error_required');
            $email = trim((string) ($current['email'] ?? ''));
        }
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
     * that is not the one of a role stays until then).
     *
     * @param array<string,mixed> $edited the avatar as the engine has it
     */
    public function edit(array $edited, array $data): array
    {
        $role = (string) ($data['role'] ?? '');
        $extra = in_array($role, self::ROLES, true) && $role !== ($edited['role'] ?? null) ? ['role' => $role] : [];
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

    /** What a manager of the grid can do to an avatar, by its status: the entries of its actions menu, in this order. */
    public const ACTIONS = [
        'active' => ['disable', 'reset'],
        'disabled' => ['enable'],
        'pending' => ['enable'],
    ];

    /** The roles of the avatars, as the engine names them from their user level. */
    public const ROLES = ['resident', 'god-like', 'customer-service', 'liaison', 'god'];

    /** The columns the list of the managers sorts on: the sort of the engine for each. */
    public const SORTS = ['name' => 'name', 'email' => 'email', 'role' => 'level', 'lastSeen' => 'lastSeen', 'created' => 'created', 'status' => 'active'];

    /**
     * The list of the managers from the query of its address (search, status, role, sort, page): the query of the engine, and the
     * query of the page as it is kept in its links.
     *
     * @param array<string,mixed> $get
     * @param int $perPage the avatars of a page
     * @return array{api: array<string,scalar>, page: array<string,string>}
     */
    public static function listQuery(array $get, int $perPage = 50): array
    {
        $page = array_filter([
            'search' => trim((string) ($get['search'] ?? '')),
            'status' => isset(self::ACTIONS[$get['status'] ?? '']) ? (string) $get['status'] : '',
            'role' => in_array($get['role'] ?? '', self::ROLES, true) ? (string) $get['role'] : '',
            'sort' => isset(self::SORTS[ltrim((string) ($get['sort'] ?? ''), '-')]) ? (string) $get['sort'] : '',
        ], fn (string $value) => $value !== '');
        $sort = $page['sort'] ?? '-created';
        $api = ['page[size]' => max(1, $perPage), 'page[number]' => max(1, (int) ($get['page'] ?? 1)), 'filter[technical]' => 'no',
            'sort' => (str_starts_with($sort, '-') ? '-' : '').self::SORTS[ltrim($sort, '-')]];
        isset($page['search']) && $api['search'] = $page['search'];
        isset($page['status']) && $api['filter[status]'] = $page['status'];
        isset($page['role']) && $api['filter[role]'] = $page['role'];

        return ['api' => $api, 'page' => $page];
    }

    /** The status of an avatar: active, disabled (by a manager), or pending (waiting for the confirmation of its email). */
    public static function status(array $avatar): string
    {
        $status = (string) ($avatar['status'] ?? '');

        return isset(self::ACTIONS[$status]) ? $status : (! empty($avatar['active']) ? 'active' : 'disabled');
    }

    /**
     * The entries of the actions menu of an avatar: the ones of its status, never disabling the manager's own avatar, no reset link
     * for an avatar without an email.
     *
     * @param ?string $self the UUID of the avatar of the manager
     * @return list<string>
     */
    public static function actions(array $avatar, ?string $self = null): array
    {
        $actions = self::ACTIONS[self::status($avatar)];
        // Never disabling the manager's own avatar; no reset link for an avatar without an email (the models, the technical ones)
        $without = [...(($avatar['uuid'] ?? null) === $self ? ['disable'] : []), ...(trim((string) ($avatar['email'] ?? '')) === '' ? ['reset'] : [])];

        return array_values(array_diff($actions, $without));
    }

    /**
     * What a manager of the grid does to an avatar: enable, disable, mail a password link.
     *
     * @param array<string,mixed> $avatar the avatar acted on (uuid, name)
     * @param ?string $self the UUID of the avatar of the manager, who never disables it
     */
    public function manage(string $action, array $avatar, ?string $self = null): void
    {
        $uuid = (string) ($avatar['uuid'] ?? '');
        ($action === 'disable' && $uuid === $self) && throw new FormError('w4os.error_invalid_request');
        match ($action) {
            'enable' => $this->ask(fn () => $this->engine->updateAvatar($uuid, ['active' => true])),
            'disable' => $this->ask(fn () => $this->engine->updateAvatar($uuid, ['active' => false])),
            'reset' => $this->ask(fn () => $this->engine->requestPasswordReset((string) $avatar['name'], $this->tokenLink('reset'), $this->sends())),
            default => throw new FormError('w4os.error_invalid_request'),
        };
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
    private function error(EngineException $e): FormError
    {
        $code = in_array($e->problemCode, self::CODES, true) ? $e->problemCode : ($e->status >= 500 ? 'internal-error' : 'invalid-request');

        return new FormError('w4os.error_'.str_replace('-', '_', $code), $e->getMessage(), $e);
    }
}
