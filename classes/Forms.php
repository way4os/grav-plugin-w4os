<?php

namespace W4os\Grav;

/**
 * What the forms of the site ask of the engine: register an avatar, confirm its email, forgotten password, the account of the
 * avatar signed in, the avatars for the managers of the grid. The rules of the names, the passwords and the models are the
 * engine's; only what the form itself adds (the password typed twice, the terms) is checked here.
 * Plain PHP: a refusal is a FormError that carries the key of its text in languages.yaml.
 */
final class Forms
{
    /** The profile fields an avatar edits on the site. */
    public const PROFILE = ['about', 'wants', 'skills', 'languages', 'firstLife', 'profileUrl'];

    /**
     * @param array<string,mixed> $config the settings of the plugin
     * @param \Closure(string $type): string $link the absolute address of a page of the plugin (register, confirm, reset...)
     */
    public function __construct(private Engine $engine, private array $config, private \Closure $link) {}

    /**
     * Register an avatar: First and last name, email, password (twice), model.
     *
     * @param array<string,mixed> $data the fields of the form
     * @return array{avatar: array<string,mixed>, confirm: bool, sent: bool} the avatar, whether it must confirm its email, whether the mail left
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
            throw new FormError('PLUGIN_W4OS.ERROR_REQUIRED');
        }
        $attributes += $confirm ? ['confirmEmail' => true, 'link' => $this->tokenLink('confirm')] : ['active' => true];
        [$avatar, $meta] = $this->ask(fn () => $this->engine->createAvatar($attributes));

        return ['avatar' => $avatar, 'confirm' => $confirm, 'sent' => ($meta['emailConfirmation'] ?? '') === 'sent'];
    }

    /** @return array<string,mixed> the avatar, now active */
    public function confirm(string $token): array
    {
        return $this->ask(fn () => $this->engine->confirmEmail(trim($token)), 'PLUGIN_W4OS.ERROR_TOKEN');
    }

    /** Mail a new confirmation link to an avatar that waits for it (by name). */
    public function resendConfirmation(string $name): void
    {
        $this->ask(fn () => $this->engine->requestEmailConfirmation(trim($name), $this->tokenLink('confirm')));
    }

    /** Mail a link to choose a new password. The answer is the same whether the name or the email is someone's or not. */
    public function forgot(array $data): void
    {
        $login = trim((string) ($data['login'] ?? ''));
        if ($login === '') {
            throw new FormError('PLUGIN_W4OS.ERROR_REQUIRED');
        }
        $this->ask(fn () => $this->engine->requestPasswordReset($login, $this->tokenLink('reset')));
    }

    /** @return array<string,mixed> the avatar whose password changed */
    public function reset(array $data): array
    {
        $this->samePassword($data);

        return $this->ask(fn () => $this->engine->resetPassword(trim((string) ($data['token'] ?? '')), (string) $data['password']), 'PLUGIN_W4OS.ERROR_TOKEN');
    }

    /**
     * Sign an avatar in.
     *
     * @return array<string,mixed> the avatar
     */
    public function signIn(string $login, string $password): array
    {
        try {
            return $this->engine->signIn($login, $password);
        } catch (EngineException $e) {
            if ($e->status === 403) {
                throw new FormError(str_contains($e->getMessage(), 'not active') ? 'PLUGIN_W4OS.ERROR_NOT_ACTIVE' : 'PLUGIN_W4OS.ERROR_SIGN_IN', previous: $e);
            }
            throw $this->error($e);
        }
    }

    /** @return array<string,mixed> the avatar */
    public function avatar(string $uuid): array
    {
        return $this->ask(fn () => $this->engine->avatar($uuid));
    }

    /**
     * The email and the profile of the avatar signed in: what changed only (an avatar has no profile until it opens it in-world).
     *
     * @param array<string,mixed> $current the avatar as the engine has it
     * @return array<string,mixed> the avatar
     */
    public function profile(array $current, array $data): array
    {
        $attributes = [];
        foreach (array_merge(['email'], self::PROFILE) as $key) {
            if (array_key_exists($key, $data) && trim((string) $data[$key]) !== trim((string) ($current[$key] ?? ''))) {
                $attributes[$key] = trim((string) $data[$key]);
            }
        }
        if (($attributes['email'] ?? 'x') === '') {
            throw new FormError('PLUGIN_W4OS.ERROR_REQUIRED');
        }
        if ($attributes === []) {
            return $current;
        }
        try {
            return $this->engine->updateAvatar((string) $current['uuid'], $attributes);
        } catch (EngineException $e) {
            if ($e->status === 503 && str_contains($e->getMessage(), 'no profile yet')) {
                throw new FormError('PLUGIN_W4OS.ERROR_NO_PROFILE', previous: $e);
            }
            throw $e->status === 403 ? new FormError('PLUGIN_W4OS.ERROR_FORBIDDEN', previous: $e) : $this->error($e);
        }
    }

    /** A new password for the avatar signed in, once its current one is checked. */
    public function password(array $avatar, array $data): void
    {
        $this->samePassword($data);
        try {
            $this->engine->signIn((string) $avatar['name'], (string) ($data['current'] ?? ''));
        } catch (EngineException $e) {
            throw $e->status === 403 ? new FormError('PLUGIN_W4OS.ERROR_CURRENT_PASSWORD', previous: $e) : $this->error($e);
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
            'reset' => $this->ask(fn () => $this->engine->requestPasswordReset((string) $avatar['name'], $this->tokenLink('reset'))),
            'delete' => $this->ask(fn () => $this->engine->deleteAvatar($uuid)),
            default => throw new FormError('PLUGIN_W4OS.ERROR_INVALID'),
        };
    }

    /** The address of a page that takes a token, `{token}` where the engine puts it. */
    private function tokenLink(string $type): string
    {
        $url = ($this->link)($type);

        return $url.(str_contains($url, '?') ? '&' : '?').'token={token}';
    }

    private function samePassword(array $data): void
    {
        if ((string) ($data['password'] ?? '') === '') {
            throw new FormError('PLUGIN_W4OS.ERROR_REQUIRED');
        }
        if ((string) $data['password'] !== (string) ($data['password_confirm'] ?? '')) {
            throw new FormError('PLUGIN_W4OS.ERROR_PASSWORD_MATCH');
        }
    }

    /**
     * A call to the engine, its problems as form errors.
     *
     * @param string $forbidden the text of a 403 (a token refused)
     */
    private function ask(\Closure $call, string $forbidden = 'PLUGIN_W4OS.ERROR_FORBIDDEN'): mixed
    {
        try {
            return $call();
        } catch (EngineException $e) {
            throw $e->status === 403 ? new FormError($forbidden, previous: $e) : $this->error($e);
        }
    }

    /** The text of a problem: what the engine says of a wrong value, a generic one otherwise. */
    private function error(EngineException $e): FormError
    {
        return match (true) {
            $e->status === 400, $e->status === 409 => new FormError('PLUGIN_W4OS.ERROR_INVALID', $e->getMessage(), $e),
            $e->status === 404 => new FormError('PLUGIN_W4OS.ERROR_NOT_FOUND', previous: $e),
            default => new FormError('PLUGIN_W4OS.ERROR_UNAVAILABLE', previous: $e),
        };
    }
}
