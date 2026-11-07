<?php

declare(strict_types=1);

namespace Asignua\FilamentScheduledReports;

use Asignua\FilamentScheduledReports\Filament\Resources\ScheduledReports\ScheduledReportResource;
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

    public function register(Panel $panel): void
    {
        $panel->resources([ScheduledReportResource::class]);
    }

    public function boot(Panel $panel): void
    {
    }
}
