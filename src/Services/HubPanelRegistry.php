<?php

namespace SmartImport\Services;

use Illuminate\Auth\Access\AuthorizationException;
use InvalidArgumentException;

/**
 * Explicit panel IDs and their Laravel Gate abilities for the future Filament adapter.
 */
final class HubPanelRegistry
{
    /** @var array<string, string> */
    private array $panels;

    /**
     * @param  array<string, string>  $panels
     */
    public function __construct(array $panels)
    {
        foreach ($panels as $panelId => $ability) {
            if (! is_string($panelId) || $panelId === '' || trim($panelId) !== $panelId || $panelId === '*') {
                throw new InvalidArgumentException('Hub panel IDs must be explicit, non-empty strings; wildcard registration is not supported.');
            }

            if (! is_string($ability) || $ability === '' || trim($ability) !== $ability || $ability === '*') {
                throw new InvalidArgumentException(sprintf('Hub panel [%s] must map to an explicit Laravel Gate ability.', $panelId));
            }
        }

        $this->panels = $panels;
    }

    /**
     * Return panel IDs on which the optional adapter may register the Hub.
     *
     * @return list<string>
     */
    public function panelIds(): array
    {
        return array_keys($this->panels);
    }

    public function isEnabledFor(string $panelId): bool
    {
        return array_key_exists($panelId, $this->panels);
    }

    public function accessAbility(string $panelId): string
    {
        if (! $this->isEnabledFor($panelId)) {
            throw new AuthorizationException(sprintf('The Smart Import Hub is not registered for panel [%s].', $panelId));
        }

        return $this->panels[$panelId];
    }
}
