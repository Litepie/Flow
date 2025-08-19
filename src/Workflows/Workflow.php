<?php

namespace Litepie\Flow\Workflows;

class Workflow
{
    protected string $name;
    protected string $label;
    protected array $states = [];
    protected array $transitions = [];
    protected array $metadata = [];

    public function __construct(string $name, string $label = '')
    {
        $this->name = $name;
        $this->label = $label ?: ucfirst($name);
    }

    public function addState($state): self
    {
        $this->states[$state->getName()] = $state;
        return $this;
    }

    public function addTransition($transition): self
    {
        $this->transitions[] = $transition;
        return $this;
    }

    public function getStates(): array
    {
        return $this->states;
    }

    public function getTransitions(): array
    {
        return $this->transitions;
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
