<?php

namespace W4os\Grav;

/**
 * What the forms of the site ask of the engine: register an avatar, confirm its email, forgotten password, the account of the
 * avatar signed in, the avatars for the managers of the grid. The rules of the names, the passwords and the models are the
 * engine's; only what the form itself adds (the password typed twice, the terms) is checked here.
 * Plain PHP: a refusal is a FormError that carries the key of its text in languages.yaml, from the code of the engine's problem.
 */
final class Forms
{
    /** The profile fields an avatar edits on the site. */
    public const PROFILE = ['about', 'wants', 'skills', 'languages', 'firstLife', 'profileUrl'];

    /** The text of each code of the problems of the engine (Problem::CODES); the others are ERROR_INVALID or ERROR_UNAVAILABLE. */
    public const ERRORS = [
        'sign-in-refused' => 'w4os.error_sign_in',
        'account-disabled' => 'w4os.error_account_disabled',
        'email-unconfirmed' => 'w4os.error_email_unconfirmed',
        'name-invalid' => 'w4os.error_name_invalid',
        'name-reserved' => 'w4os.error_name_reserved',
        'name-taken' => 'w4os.error_name_taken',
        'password-required' => 'w4os.error_required',
        'password-invalid' => 'w4os.error_password_invalid',
        'email-invalid' => 'w4os.error_email_invalid',
        'email-required' => 'w4os.error_email_required',
        'model-unknown' => 'w4os.error_model_unknown',
        'token-refused' => 'w4os.error_token',
        'no-confirmation-pending' => 'w4os.error_no_confirmation_pending',
        'mail-failed' => 'w4os.error_mail_failed',
        'not-found' => 'w4os.error_not_found',
        'invalid-request' => 'w4os.error_invalid',
        'field-unknown' => 'w4os.error_invalid',
        'link-invalid' => 'w4os.error_invalid',
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
     * The email and the profile of the avatar signed in: what changed only. A new email changes once the avatar clicks the link
     * mailed to it.
     *
     * @param array<string,mixed> $current the avatar as the engine has it
     * @return array{avatar: array<string,mixed>, email: ?string, link: ?string} the avatar, the new email waiting for its
     *     confirmation, its link when it was not mailed (test site)
     */
    public function profile(array $current, array $data): array
    {
        $attributes = [];
        foreach (self::PROFILE as $key) {
            if (array_key_exists($key, $data) && trim((string) $data[$key]) !== trim((string) ($current[$key] ?? ''))) {
                $attributes[$key] = trim((string) $data[$key]);
            }
        }
        $email = trim((string) ($data['email'] ?? ($current['email'] ?? '')));
        if ($email === '') {
            throw new FormError('w4os.error_required');
        }
        $changed = strtolower($email) !== strtolower(trim((string) ($current['email'] ?? '')));
        if ($changed) {
            $attributes += ['email' => $email, 'confirmEmail' => true, 'link' => $this->tokenLink('confirm', ['change' => 1])] + ($this->sends() ? [] : ['send' => false]);
        }
        if ($attributes === []) {
            return ['avatar' => $current, 'email' => null, 'link' => null];
        }
        [$avatar, $meta] = $this->ask(fn () => $this->engine->updateAvatar((string) $current['uuid'], $attributes));

        return ['avatar' => $avatar, 'email' => $changed ? $email : null, 'link' => $meta['confirmation']['link'] ?? null];
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
     * What a manager of the grid does to an avatar: enable, disable, mail a password link, delete.
     *
     * @param array<string,mixed> $avatar the avatar acted on (uuid, name)
     */
    public function manage(string $action, array $avatar): void
    {
        $uuid = (string) ($avatar['uuid'] ?? '');
        match ($action) {
            'enable' => $this->ask(fn () => $this->engine->updateAvatar($uuid, ['active' => true])),
            'disable' => $this->ask(fn () => $this->engine->updateAvatar($uuid, ['active' => false])),
            'reset' => $this->ask(fn () => $this->engine->requestPasswordReset((string) $avatar['name'], $this->tokenLink('reset'), $this->sends())),
            'delete' => $this->ask(fn () => $this->engine->deleteAvatar($uuid)),
            default => throw new FormError('w4os.error_invalid'),
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

    /** The text of a problem, by its code; what the engine says is kept for the codes that only say "a value is wrong". */
    private function error(EngineException $e): FormError
    {
        $key = self::ERRORS[$e->problemCode] ?? 'w4os.error_unavailable';

        return new FormError($key, $key === 'w4os.error_invalid' ? $e->getMessage() : '', $e);
    }
}
