<?php

namespace Litepie\Flow\Contracts;

use Litepie\Flow\States\State;

interface StateManagerContract
{
    public function create(string $name, array $config = []): State;
    public function get(string $name): State;
    public function has(string $name): bool;
    public function all(): array;
    public function register(string $name, State $state): void;
}
