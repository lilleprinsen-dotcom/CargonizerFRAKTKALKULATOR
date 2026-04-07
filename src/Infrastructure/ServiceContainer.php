<?php

namespace Lilleprinsen\Cargonizer\Infrastructure;

use Closure;
use RuntimeException;

final class ServiceContainer
{
    /** @var array<string,Closure(self):mixed> */
    private array $factories = [];

    /** @var array<string,mixed> */
    private array $instances = [];

    public function set(string $id, Closure $factory): void
    {
        $this->factories[$id] = $factory;
    }

    public function get(string $id)
    {
        if (array_key_exists($id, $this->instances)) {
            return $this->instances[$id];
        }

        if (!isset($this->factories[$id])) {
            throw new RuntimeException(sprintf('Service "%s" is not registered.', $id));
        }

        $this->instances[$id] = $this->factories[$id]($this);

        return $this->instances[$id];
    }
}
