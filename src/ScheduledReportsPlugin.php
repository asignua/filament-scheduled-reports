<?php

declare(strict_types=1);

namespace Asignua\FilamentScheduledReports;

use Asignua\FilamentScheduledReports\Filament\Resources\ScheduledReports\ScheduledReportResource;
use Asignua\FilamentScheduledReports\Models\ScheduledReport;
use Closure;
use Filament\Contracts\Plugin;
use Filament\Panel;
use Illuminate\Contracts\Auth\Authenticatable;

class ScheduledReportsPlugin implements Plugin
{
    public const string ID = 'asignua-filament-scheduled-reports';

    /** @var (Closure(Authenticatable): array<int|string, string>)|null */
    protected ?Closure $recipients = null;

    protected bool $allowExternal = false;

    /** @var (Closure(Authenticatable, Panel, ScheduledReport): mixed)|null */
    protected ?Closure $beforeRun = null;

    protected bool $requireScopeHook = false;

    protected ?string $urlRoot = null;

    /** @var list<string> */
    protected array $domains = [];

    public static function make(): static
    {
        return app(static::class);
    }

    public static function forPanel(?Panel $panel): ?static
    {
        if ($panel === null || !$panel->hasPlugin(self::ID)) {
            return null;
        }

        $plugin = $panel->getPlugin(self::ID);

        return $plugin instanceof static ? $plugin : null;
    }

    public function getId(): string
    {
        return self::ID;
    }

    /**
     * Who the owner may pick besides themselves: `fn (Authenticatable $owner): array<id, label>` of
     * users of this panel. Without it a report goes only to its owner. The list is checked again at every
     * send, so a person who is no longer offered stops receiving.
     *
     * @param Closure(Authenticatable): array<int|string, string> $callback
     */
    public function recipients(Closure $callback): static
    {
        $this->recipients = $callback;

        return $this;
    }

    /**
     * Allows plain email addresses as recipients. Off by default, because the file holds whatever the
     * owner sees. Works only together with `allowedDomains()`: with no domain listed no outside address is accepted.
     */
    public function allowExternalRecipients(bool $condition = true): static
    {
        $this->allowExternal = $condition;

        return $this;
    }

    /**
     * @param list<string> $domains e.g. ['example.com']; matched exactly, a subdomain is a different domain
     */
    public function allowedDomains(array $domains): static
    {
        $this->domains = array_values(array_unique(array_map(static fn (string $domain): string => strtolower(ltrim(trim($domain), '@')), $domains)));

        return $this;
    }

    /**
     * @return array<int|string, string>
     */
    public function recipientOptions(Authenticatable $owner): array
    {
        return $this->recipients === null ? [] : ($this->recipients)($owner);
    }

    public function allowsExternalRecipients(): bool
    {
        return $this->allowExternal && $this->domains !== [];
    }

    /**
     * @return list<string>
     */
    public function getAllowedDomains(): array
    {
        return $this->domains;
    }

    public function isDomainAllowed(string $email): bool
    {
        $at = strrpos($email, '@');

        return $at !== false && in_array(strtolower(substr($email, $at + 1)), $this->domains, true);
    }

    /**
     * Re-applies the owner's data scope in the worker. READ THIS BEFORE YOU SCHEDULE ANYTHING.
     *
     * A run happens in a queue worker as the report's owner, but the panel's middleware, the
     * `ServingFilament` event and a second panel's `bootUsing()` do NOT run there. Anything that narrows what the owner
     * sees in the browser through those (a tenant-like global scope, a "current branch" setting, a
     * session value) is missing in the worker, and the mail would carry every row. Set the same scope here:
     * `fn (Authenticatable $owner, Panel $panel, ScheduledReport $report) => …`. A worker serves many jobs, so
     * derive the scope from `$owner` (or `auth()`), never from a value left behind by the previous run.
     *
     * @param Closure(Authenticatable, Panel, ScheduledReport): mixed $callback
     */
    public function beforeRun(Closure $callback): static
    {
        $this->beforeRun = $callback;

        return $this;
    }

    /**
     * Refuses to schedule (no button, no save, and a stored report pauses) on a panel that has no
     * `beforeRun()` hook. For panels whose data scope lives outside the models' own global scopes and policies.
     */
    public function requireScopeHook(bool $condition = true): static
    {
        $this->requireScopeHook = $condition;

        return $this;
    }

    /**
     * The root URL the unsubscribe and download links are built on, when it must differ from `app.url`.
     * Without it a report uses `app.url`, or the request's own root only when the request came in on one of the panel's configured domains.
     */
    public function urlRoot(?string $root): static
    {
        $this->urlRoot = $root === null ? null : rtrim($root, '/');

        return $this;
    }

    public function getUrlRoot(): ?string
    {
        return $this->urlRoot;
    }

    public function hasScopeHook(): bool
    {
        return $this->beforeRun !== null;
    }

    public function requiresScopeHook(): bool
    {
        return $this->requireScopeHook;
    }

    /**
     * Whether a report may be scheduled or run on this panel as far as the scope hook goes.
     */
    public function scopeHookSatisfied(): bool
    {
        return !$this->requireScopeHook || $this->beforeRun !== null;
    }

    public function runBeforeRun(Authenticatable $owner, Panel $panel, ScheduledReport $report): void
    {
        if ($this->beforeRun !== null) {
            ($this->beforeRun)($owner, $panel, $report);
        }
    }

    public function register(Panel $panel): void
    {
        $panel->resources([ScheduledReportResource::class]);
    }

    public function boot(Panel $panel): void {}
}
