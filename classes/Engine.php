<?php

namespace W4os\Grav;

/**
 * The OpenSim engine API, as the site uses it: JSON:API documents, problems (RFC 9457) thrown as EngineException, the API key of the
 * site sent as a Bearer token. Plain PHP: the transport is given (Grav's HTTP client in the site, a fake one in the tests).
 *
 * The address is the one of the engine API, its own (`https://grid.example.org/api`, setting api_url). A host that serves one
 * grid answers without `/grids/{grid}`; one that serves several needs the nick of the grid.
 */
final class Engine
{
    /** @var ?array<string,mixed> */
    private ?array $info = null;

    /**
     * @param string $url the address of the API
     * @param string $key the API key of the site (`opensim api-key create`)
     * @param string $grid the nick of the grid, when the API serves several
     * @param \Closure(string $method, string $url, array<string,string> $headers, ?string $body): array{int, string} $send
     * @param bool $local the engine the plugin bundles, called as a library: no address, no key
     * @param string $assets the public address of the pictures of the grid, when it is not the one of the API
     */
    public function __construct(
        private string $url,
        private string $key,
        private string $grid,
        private \Closure $send,
        private bool $local = false,
        private string $assets = '',
    ) {
        $this->url = rtrim($url, '/');
    }

    /** Whether the site can talk to the engine: the bundled one, or an API with its address and key. */
    public function ready(): bool
    {
        return $this->local || ($this->url !== '' && $this->key !== '');
    }

    /** The public address of a picture of the grid (a model, a profile), by its asset UUID. */
    public function assetUrl(string $uuid, string $format = 'jpg'): string
    {
        return $this->assetsUrl().'/'.rawurlencode($uuid).'.'.$format;
    }

    /** The address the pictures of the grid are under (<UUID>.<format>). */
    public function assetsUrl(): string
    {
        return $this->assets !== '' ? rtrim($this->assets, '/') : $this->url.$this->path('/assets');
    }

    /** The address of the grid's own logo, as its helpers have it (LogoURL), null when it has none. */
    public function gridLogo(): ?string
    {
        return ($this->info()['logo'] ?? null) ?: null;
    }

    /**
     * Sign an avatar in: its name (`First Last`) or email, and its password.
     *
     * @return array<string,mixed> the attributes of the avatar
     */
    public function signIn(string $login, string $password): array
    {
        $key = str_contains($login, '@') ? 'email' : 'name';

        return $this->resource($this->call('POST', '/sign-ins', ['type' => 'sign-ins', 'attributes' => [$key => trim($login), 'password' => $password]]));
    }

    /** The name of the grid, as its grid info says it (`gridname`). */
    public function gridName(): string
    {
        return (string) ($this->info()['elements']['gridname'] ?? ($this->info()['name'] ?? ''));
    }

    /** The login URI of the grid as a viewer takes it (host:port), as the engine writes it. */
    public function loginUri(): string
    {
        return (string) ($this->info()['uri'] ?? '');
    }

    /** @return array<string,mixed> what the engine says of the grid (/info) */
    private function info(): array
    {
        return $this->info ??= $this->resource($this->call('GET', '/info'));
    }

    /**
     * The avatar models of the grid.
     *
     * @return list<array<string,mixed>> their attributes
     */
    public function models(): array
    {
        try {
            $document = $this->call('GET', '/avatar-models');
        } catch (EngineException $e) {
            // A grid without models
            if ($e->status === 404) {
                return [];
            }
            throw $e;
        }

        return array_map(fn (array $item) => (array) $item['attributes'] + ['id' => $item['id'] ?? null], (array) ($document['data'] ?? []));
    }

    /**
     * Make an avatar.
     *
     * @param array<string,mixed> $attributes name, email, password, model, confirmEmail, link, active
     * @return array{array<string,mixed>, array<string,mixed>} the attributes of the avatar and the meta of the answer
     */
    public function createAvatar(array $attributes): array
    {
        $document = $this->call('POST', '/avatars', ['type' => 'avatars', 'attributes' => array_filter($attributes, fn ($v) => $v !== null && $v !== '')]);

        return [$this->resource($document), (array) ($document['meta'] ?? [])];
    }

    /** @return array<string,mixed> the attributes of an avatar, by UUID or name */
    public function avatar(string $id): array
    {
        $document = $this->call('GET', '/avatars/'.rawurlencode($id));

        // What the item says beside its attributes (meta: the settings of its grid, its form as its grid has it)
        return $this->resource($document) + (isset($document['meta']) ? ['meta' => (array) $document['meta']] : []);
    }

    /** @return array<string,mixed> the settings of the grid of a group (models...), as the grid says them, else their defaults */
    public function settings(string $group): array
    {
        return $this->resource($this->call('GET', '/settings/'.rawurlencode($group)));
    }

    /**
     * @param array<string,mixed> $values the settings changed
     * @return array<string,mixed> the settings of the group now
     */
    public function updateSettings(string $group, array $values): array
    {
        return $this->resource($this->call('PATCH', '/settings/'.rawurlencode($group), ['type' => 'settings', 'id' => $group, 'attributes' => $values]));
    }

    /** @return array<string,mixed> the health of the grid as the engine checks it now: its level, its groups of rows */
    public function health(): array
    {
        return $this->resource($this->call('GET', '/health'));
    }

    /** @return array<string,mixed> the addresses of the grid, where each comes from and who serves the pages */
    public function addresses(): array
    {
        return $this->resource($this->call('GET', '/addresses'));
    }

    /**
     * The OpenAPI document of the engine (`/openapi.json`, at the root of the API): what it gives, its lists among them (W4OS\Views\Contract).
     *
     * @return array<string,mixed>
     */
    public function openapi(): array
    {
        return $this->call('GET', '/openapi.json', grid: false);
    }

    /**
     * The picks or the classifieds of an avatar (`picks`, `classifieds`), as its profile shows them: name, description, picture,
     * parcel, region, position, teleport (and created, expires for a classified).
     *
     * @return list<array<string,mixed>>
     */
    public function avatarItems(string $id, string $kind): array
    {
        $document = $this->call('GET', '/avatars/'.rawurlencode($id).'/'.rawurlencode($kind));

        return array_values(array_map(fn ($resource) => (array) ($resource['attributes'] ?? []), (array) ($document['data'] ?? [])));
    }

    /**
     * Change an avatar. Its email with `confirmEmail` (and `link`, `send`) changes only once the new address is confirmed.
     *
     * @param array<string,mixed> $attributes email, password, active, about, profileUrl, wants, skills, languages, firstLife
     * @return array{array<string,mixed>, array<string,mixed>} the attributes of the avatar and the meta of the answer
     */
    public function updateAvatar(string $id, array $attributes): array
    {
        $document = $this->call('PATCH', '/avatars/'.rawurlencode($id), ['type' => 'avatars', 'id' => $id, 'attributes' => $attributes]);

        return [$this->resource($document), (array) ($document['meta'] ?? [])];
    }

    /**
     * A page of the items of a type (avatars, regions...).
     *
     * @param array<string,scalar> $query search, sort, filter[...], page[number], page[size]
     * @return array{list<array<string,mixed>>, array<string,mixed>} the attributes of each, and the meta (total, page, perPage)
     */
    public function items(string $type, array $query = [], bool $grid = true): array
    {
        $document = $this->call('GET', '/'.rawurlencode($type), null, $query, $grid);

        return [array_map(fn (array $item) => self::attributes($item), (array) ($document['data'] ?? [])), (array) ($document['meta'] ?? [])];
    }

    /** @return array<string,mixed> the state of the grid: its level, its simulators (regions, online), its regions (total, online) */
    public function monitor(): array
    {
        return $this->resource($this->call('GET', '/monitor'));
    }

    /** @return array<string,mixed> the attributes of an item of a type */
    public function item(string $type, string $id, bool $grid = true): array
    {
        return $this->resource($this->call('GET', '/'.rawurlencode($type).'/'.rawurlencode($id), null, [], $grid));
    }

    /** The nick of the grid the site asks, empty for an API of one grid. */
    public function grid(): string
    {
        return $this->grid;
    }

    /**
     * Mail a link to choose a new password to the avatar a name or an email is. The same answer whether it is someone's or not.
     *
     * @return ?array<string,mixed> what the engine says it did (its notice), null when it says nothing
     * @param string $link the page that takes the token, `{token}` in it
     */
    public function requestPasswordReset(string $login, string $link, bool $send = true): ?array
    {
        $key = str_contains($login, '@') ? 'email' : 'name';
        $document = $this->call('POST', '/password-resets', ['type' => 'password-resets', 'attributes' => [$key => trim($login), 'link' => $link] + ($send ? [] : ['send' => false])]);

        return self::notice($document);
    }

    /**
     * What the engine says it did, to tell the visitor (its notice: code, params, English detail), null when it says nothing.
     *
     * @param array<string,mixed> $document
     * @return ?array<string,mixed>
     */
    private static function notice(array $document): ?array
    {
        $notice = $document['meta']['notice'] ?? null;

        return is_array($notice) && isset($notice['code']) ? $notice : null;
    }

    /** @return array<string,mixed> the avatar whose password changed */
    public function resetPassword(string $token, string $password): array
    {
        return $this->resource($this->call('POST', '/password-resets/complete', ['type' => 'password-resets', 'attributes' => ['token' => $token, 'password' => $password]]));
    }

    /**
     * Mail a new link to confirm its email to an avatar that waits for it.
     *
     * @return array<string,mixed> the token given (token, link, expires) when it is not mailed, else what the engine says (notice)
     */
    public function requestEmailConfirmation(string $avatar, string $link, bool $send = true): array
    {
        $document = $this->call('POST', '/email-confirmations', ['type' => 'email-confirmations', 'attributes' => ['avatar' => $avatar, 'link' => $link] + ($send ? [] : ['send' => false])]);

        return $send ? ['notice' => self::notice($document)] : (array) ($document['data'][0]['attributes'] ?? []);
    }

    /** @return array<string,mixed> the avatar, now active */
    public function confirmEmail(string $token): array
    {
        return $this->resource($this->call('POST', '/email-confirmations/complete', ['type' => 'email-confirmations', 'attributes' => ['token' => $token]]));
    }

    /**
     * A call to the API.
     *
     * @param ?array<string,mixed> $data the primary data of the body (JSON:API `data`)
     * @param array<string,scalar> $query
     * @param bool $grid under the grid of the site (`/grids/{grid}`, when the API serves several), else at the root of the API
     * @return array<string,mixed> the document answered (empty for 204)
     * @throws EngineException the problem the API answered, or the API cannot be reached
     */
    public function call(string $method, string $path, ?array $data = null, array $query = [], bool $grid = true): array
    {
        if (! $this->ready()) {
            throw new EngineException(503, 'The site is not connected to the engine (bundled, or an API address and key).');
        }
        $url = $this->url.($grid ? $this->path($path) : $path).($query === [] ? '' : '?'.http_build_query($query));
        $headers = ['Accept' => 'application/vnd.api+json, application/problem+json'] + ($this->key === '' ? [] : ['Authorization' => 'Bearer '.$this->key]);
        $body = null;
        if ($data !== null) {
            $headers['Content-Type'] = 'application/vnd.api+json';
            $body = (string) json_encode(['data' => $data], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
        try {
            [$status, $answer] = ($this->send)($method, $url, $headers, $body);
        } catch (\Throwable $e) {
            throw new EngineException(503, 'The engine API cannot be reached: '.$e->getMessage(), previous: $e);
        }
        $document = $answer === '' ? [] : json_decode($answer, true);
        if (! is_array($document)) {
            throw new EngineException($status >= 400 ? $status : 502, "The engine API answered $status with no JSON document.");
        }
        if ($status >= 400) {
            throw new EngineException($status, (string) ($document['detail'] ?? $document['title'] ?? "Error $status"), $document);
        }

        return $document;
    }

    /** The path of the API, under the grid when it serves several. */
    private function path(string $path): string
    {
        return $this->grid === '' ? $path : '/grids/'.rawurlencode($this->grid).$path;
    }

    /** @return array<string,mixed> the attributes of the primary resource of a document */
    private function resource(array $document): array
    {
        return isset($document['data']) && is_array($document['data']) ? self::attributes($document['data']) : [];
    }

    /**
     * The attributes of a resource, with its id when they have none of their own (a simulator, `host:port`).
     *
     * @param array<string,mixed> $resource
     * @return array<string,mixed>
     */
    private static function attributes(array $resource): array
    {
        $attributes = (array) ($resource['attributes'] ?? []);

        return $attributes + (isset($resource['id']) && ! isset($attributes['uuid']) ? ['id' => (string) $resource['id']] : []);
    }
}
