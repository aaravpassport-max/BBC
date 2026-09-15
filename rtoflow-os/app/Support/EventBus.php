<?php

namespace RTOFLOW\Support;

if (!defined('ABSPATH')) exit;

/**
 * Internal Event Bus
 *
 * Lightweight pub/sub. Also fires WordPress actions so external plugins
 * can hook into RTOFLOW events.
 */
class EventBus
{
    private array $listeners = [];

    public function listen(string $event, callable $cb): void
    {
        $this->listeners[$event][] = $cb;
    }

    public function fire(string $event, array $payload = []): void
    {
        foreach ($this->listeners[$event] ?? [] as $cb) {
            $cb($payload);
        }
        do_action('rtoflow_' . str_replace('.', '_', $event), $payload);
    }
}
