<?php

declare(strict_types=1);

namespace Flytachi\Winter\Cast\Common;

class CastHeader
{
    private array $headers = [];

    public static function instance(): self
    {
        return (new self());
    }

    public function set(string $key, string $value): self
    {
        $this->headers[$key] = $value;
        return $this;
    }

    public function remove(string $key): self
    {
        unset($this->headers[$key]);
        return $this;
    }

    public function authBearer(string $token): self
    {
        return $this->set('Authorization', 'Bearer ' . $token);
    }

    public function authBasic(string $username, string $password): self
    {
        return $this->set('Authorization',
            'Basic ' . base64_encode($username . ':' . $password)
        );
    }

    public function json(): self
    {
        return $this->set('Accept', 'application/json')
            ->set('Content-Type', 'application/json');
    }

    public function userAgent(string $agent): self
    {
        return $this->set('User-Agent', $agent);
    }

    public function acceptLanguage(string $language): self
    {
        return $this->set('Accept-Language', $language);
    }

    public function referer(string $url): self
    {
        return $this->set('Referer', $url);
    }

    public function contentType(string $type): self
    {
        return $this->set('Content-Type', $type);
    }

    public function toArray(): array
    {
        $result = [];
        foreach ($this->headers as $key => $value) {
            $result[] = "$key: $value";
        }
        return $result;
    }
}