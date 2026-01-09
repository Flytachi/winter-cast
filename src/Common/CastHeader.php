<?php

declare(strict_types=1);

namespace Flytachi\Winter\Cast\Common;

class CastHeader
{
    private array $headers = [];

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

    public static function authBearer(string $token): self
    {
        return (new self)->set('Authorization', 'Bearer ' . $token);
    }

    public static function authBasic(string $username, string $password): self
    {
        return (new self)->set('Authorization',
            'Basic ' . base64_encode($username . ':' . $password)
        );
    }

    public static function acceptJson(): self
    {
        return (new self)->set('Accept', 'application/json');
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