<?php

namespace SmartImport\Services;

use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;
use SmartImport\Contracts\ImportPresetScope;
use SmartImport\Contracts\ImportSessionScope;
use SmartImport\Models\ImportPreset;
use SmartImport\Models\ImportSession;

final class ImportAuthorization
{
    public function __construct(
        private Gate $gate,
        private ImportSessionScope $sessionScope,
        private ImportPresetScope $presetScope,
        private HubPanelRegistry $hubPanels,
    ) {}

    /**
     * Authorize one operation using Laravel Gate and an explicit actor.
     */
    public function authorize(string $ability, Authenticatable $actor, mixed $subject = null): void
    {
        $this->gate->forUser($actor)->authorize($ability, $subject);
    }

    /** Check an optional field-level capability without turning denial into an exception. */
    public function allows(string $ability, Authenticatable $actor, mixed $arguments = null): bool
    {
        return $this->gate->forUser($actor)->allows($ability, $arguments);
    }

    /**
     * Authorize the Hub entry point configured for one explicit Filament panel ID.
     */
    public function authorizeHubPanel(string $panelId, Authenticatable $actor): void
    {
        $ability = $this->hubPanels->accessAbility($panelId);

        $this->authorize($ability, $actor, $panelId);
    }

    /**
     * Authorize session discovery before applying the actor/tenant query scope.
     *
     * @param  Builder<ImportSession>  $query
     * @return Builder<ImportSession>
     */
    public function scopeSessions(Builder $query, Authenticatable $actor): Builder
    {
        $this->authorize('viewAny', $actor, ImportSession::class);

        return $this->applyActorScope($query, $actor);
    }

    /**
     * Apply the actor/tenant boundary before a caller looks up one session.
     * The caller must authorize the requested per-session operation afterward.
     *
     * @param  Builder<ImportSession>  $query
     * @return Builder<ImportSession>
     */
    public function applyActorScope(Builder $query, Authenticatable $actor): Builder
    {
        return $this->sessionScope->apply($query, $actor);
    }

    /**
     * Authorize preset discovery before restricting it to the actor's trusted sharing scope.
     *
     * @param Builder<ImportPreset> $query
     * @return Builder<ImportPreset>
     */
    public function scopePresets(Builder $query, Authenticatable $actor): Builder
    {
        $this->authorize('viewAny', $actor, ImportPreset::class);

        return $this->applyPresetScope($query, $actor);
    }

    /**
     * Apply the configured trusted sharing boundary before looking up a preset.
     * The caller must authorize the requested per-preset operation afterward.
     *
     * @param Builder<ImportPreset> $query
     * @return Builder<ImportPreset>
     */
    public function applyPresetScope(Builder $query, Authenticatable $actor): Builder
    {
        return $this->presetScope->apply($query, $actor);
    }

    /** Return the validated opaque scope key persisted with one of the actor's presets. */
    public function presetScopeKey(Authenticatable $actor): string
    {
        $key = $this->presetScope->keyFor($actor);

        if ($key === '' || trim($key) !== $key || mb_strlen($key) > 191) {
            throw new InvalidArgumentException('The configured mapping preset scope must return a trimmed non-empty key of at most 191 characters.');
        }

        return $key;
    }
}
