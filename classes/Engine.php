<?php

namespace W4os\Grav;

/**
 * The OpenSim engine API, as the site uses it: JSON:API documents, problems (RFC 9457) thrown as EngineException, the API key of the
 * site sent as a Bearer token. Plain PHP: the transport is given (Grav's HTTP client in the site, a fake one in the tests).
 *
 * The address is the one of the API (`https://grid.example.org/helpers/api`). A host that serves one grid answers without
 * `/grids/{grid}`; one that serves several needs the nick of the grid.
 */
final class Engine
{
    /**
     * @param string $url the address of the API
     * @param string $key the API key of the site (`opensim api-key create`)
     * @param string $grid the nick of the grid, when the API serves several
     * @param \Closure(string $method, string $url, array<string,string> $headers, ?string $body): array{int, string} $send
     */
    public function __construct(
        private string $url,
        private string $key,
        private string $grid,
        private \Closure $send,
    ) {
        $this->url = rtrim($url, '/');
    }

    /** Whether the site can talk to the API: an address and a key. */
    public function ready(): bool
    {
        return $this->url !== '' && $this->key !== '';
    }

    /** The public address of a picture of the grid (a model, a profile), by its asset UUID. */
    public function assetUrl(string $uuid, string $format = 'jpg'): string
    {
        return $this->url.$this->path('/assets/'.rawurlencode($uuid).'.'.$format);
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
        return $this->resource($this->call('GET', '/avatars/'.rawurlencode($id)));
    }

    /**
     * @param array<string,mixed> $attributes email, password, active, about, profileUrl, wants, skills, languages, firstLife
     * @return array<string,mixed>
     */
    public function updateAvatar(string $id, array $attributes): array
    {
        return $this->resource($this->call('PATCH', '/avatars/'.rawurlencode($id), ['type' => 'avatars', 'id' => $id, 'attributes' => $attributes]));
    }

    public function deleteAvatar(string $id): void
    {
        $this->call('DELETE', '/avatars/'.rawurlencode($id));
    }

    /**
     * A page of the avatars.
     *
     * @param array<string,scalar> $query search, sort, filter[...], page[number], page[size]
     * @return array{list<array<string,mixed>>, array<string,mixed>} the attributes of each, and the meta (total, page, perPage)
     */
    public function avatars(array $query = []): array
    {
        $document = $this->call('GET', '/avatars', null, $query);

        return [array_map(fn (array $item) => (array) $item['attributes'], (array) ($document['data'] ?? [])), (array) ($document['meta'] ?? [])];
    }

    /**
     * Mail a link to choose a new password to the avatar a name or an email is. The same answer whether it is someone's or not.
     *
     * @param string $link the page that takes the token, `{token}` in it
     */
    public function requestPasswordReset(string $login, string $link): void
    {
        $key = str_contains($login, '@') ? 'email' : 'name';
        $this->call('POST', '/password-resets', ['type' => 'password-resets', 'attributes' => [$key => trim($login), 'link' => $link]]);
    }

    /** @return array<string,mixed> the avatar whose password changed */
    public function resetPassword(string $token, string $password): array
    {
        return $this->resource($this->call('POST', '/password-resets/complete', ['type' => 'password-resets', 'attributes' => ['token' => $token, 'password' => $password]]));
    }

    /** Mail a new link to confirm its email to an avatar that waits for it. */
    public function requestEmailConfirmation(string $avatar, string $link): void
    {
        $this->call('POST', '/email-confirmations', ['type' => 'email-confirmations', 'attributes' => ['avatar' => $avatar, 'link' => $link]]);
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
     * @return array<string,mixed> the document answered (empty for 204)
     * @throws EngineException the problem the API answered, or the API cannot be reached
     */
    public function call(string $method, string $path, ?array $data = null, array $query = []): array
    {
        if (! $this->ready()) {
            throw new EngineException(503, 'The site is not connected to the engine API (address and key).');
        }
        $url = $this->url.$this->path($path).($query === [] ? '' : '?'.http_build_query($query));
        $headers = ['Authorization' => 'Bearer '.$this->key, 'Accept' => 'application/vnd.api+json, application/problem+json'];
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
            throw new EngineException($status, (string) ($document['detail'] ?? $document['title'] ?? "Error $status"), (string) ($document['type'] ?? ''), $document);
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
        return (array) ($document['data']['attributes'] ?? []);
    }
}
