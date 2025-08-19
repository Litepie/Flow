<?php

namespace Litepie\Flow\States;

class State
{
    protected string $name;
    protected string $label;
    protected bool $initial;
    protected bool $final;
    protected array $metadata = [];

    public function __construct(string $name, string $label = '', bool $initial = false, bool $final = false)
    {
        $this->name = $name;
        $this->label = $label ?: ucfirst($name);
        $this->initial = $initial;
        $this->final = $final;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function isInitial(): bool
    {
        return $this->initial;
    }

    public function isFinal(): bool
    {
        return $this->final;
    }

    public function setMetadata(string $key, $value): self
    {
        $this->metadata[$key] = $value;
        return $this;
    }

    public function getMetadata(string $key = null)
    {
        return $key ? ($this->metadata[$key] ?? null) : $this->metadata;
    }
}
