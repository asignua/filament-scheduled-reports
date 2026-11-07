<?php

declare(strict_types=1);

namespace Asignua\FilamentScheduledReports\Support;

use Asignua\FilamentScheduledReports\Enums\Frequency;
use Asignua\FilamentScheduledReports\ScheduledReportsPlugin;
use Closure;
use DateTimeZone;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Carbon;

/**
 * The schedule and recipient fields, shared by the "Schedule report" modal and the resource's edit form,
 * so the two cannot drift apart.
 */
final class ScheduleFields
{
    /**
     * @return array<int, mixed>
     */
    public static function make(?ScheduledReportsPlugin $plugin, Authenticatable $user): array
    {
        $lang = 'filament-scheduled-reports::filament-scheduled-reports.';
        $fields = [
            TextInput::make('name')->label(__($lang.'name'))->required()->maxLength(120),
            Select::make('frequency')
                ->label(__($lang.'frequency'))
                ->options(collect(Frequency::cases())->mapWithKeys(fn (Frequency $case): array => [$case->value => $case->label()])->all())
                ->live()
                ->required()
                ->native(false),
            CheckboxList::make('days')
                ->label(__($lang.'days'))
                ->options(self::weekdays())
                ->columns(4)
                ->visible(static fn (Get $get): bool => $get('frequency') === Frequency::Weekly->value)
                ->required(static fn (Get $get): bool => $get('frequency') === Frequency::Weekly->value),
            Select::make('day_of_month')
                ->label(__($lang.'day_of_month'))
                ->helperText(__($lang.'day_of_month_hint'))
                ->options(array_combine(range(1, 31), range(1, 31)))
                ->visible(static fn (Get $get): bool => $get('frequency') === Frequency::Monthly->value)
                ->required(static fn (Get $get): bool => $get('frequency') === Frequency::Monthly->value)
                ->native(false),
            TextInput::make('time')
                ->label(__($lang.'time'))
                ->type('time')
                ->required()
                ->rules(['regex:/^([01]\d|2[0-3]):[0-5]\d$/']),
            Select::make('timezone')
                ->label(__($lang.'timezone'))
                ->options(array_combine(DateTimeZone::listIdentifiers(), DateTimeZone::listIdentifiers()))
                ->searchable()
                ->required()
                ->native(false),
            Select::make('recipients')
                ->label(__($lang.'recipients'))
                ->helperText(__($lang.'recipients_hint'))
                ->multiple()
                ->options(self::recipientOptions($plugin, $user))
                ->required()
                ->native(false),
        ];

        if ($plugin?->allowsExternalRecipients()) {
            $fields[] = TagsInput::make('external')
                ->label(__($lang.'external'))
                ->helperText(__($lang.'external_hint', ['domains' => implode(', ', $plugin->getAllowedDomains())]))
                ->rules([static fn (): Closure => self::externalRule($plugin)]);
        }

        $fields[] = Toggle::make('skip_empty')->label(__($lang.'skip_empty'));

        return $fields;
    }

    /**
     * @return array<string, string>
     */
    public static function recipientOptions(?ScheduledReportsPlugin $plugin, Authenticatable $user): array
    {
        $own = (string) $user->getAuthIdentifier();
        $options = [$own => __('filament-scheduled-reports::filament-scheduled-reports.me').' ('.(Recipients::email($user) ?? Recipients::name($user)).')'];

        foreach ($plugin?->recipientOptions($user) ?? [] as $id => $label) {
            $options[(string) $id] ??= (string) $label;
        }

        return $options;
    }

    /**
     * The stored list from the form's values, keeping only what the owner is allowed to pick. Whatever the
     * browser sent beyond the offered options is dropped, not trusted.
     *
     * @param array<string, mixed> $data
     *
     * @return list<array{type: string, id?: string, email?: string}>
     */
    public static function recipientsFrom(array $data, ?ScheduledReportsPlugin $plugin, Authenticatable $user): array
    {
        $allowed = array_map('strval', array_keys(self::recipientOptions($plugin, $user)));
        $recipients = [];

        foreach (is_array($data['recipients'] ?? null) ? $data['recipients'] : [] as $id) {
            if (in_array((string) $id, $allowed, true)) {
                $recipients[] = ['type' => 'user', 'id' => (string) $id];
            }
        }

        if ($plugin?->allowsExternalRecipients()) {
            foreach (is_array($data['external'] ?? null) ? $data['external'] : [] as $email) {
                if (is_string($email) && filter_var($email, FILTER_VALIDATE_EMAIL) !== false && $plugin->isDomainAllowed($email)) {
                    $recipients[] = ['type' => 'email', 'email' => strtolower($email)];
                }
            }
        }

        return $recipients;
    }

    private static function externalRule(ScheduledReportsPlugin $plugin): Closure
    {
        return static function (string $attribute, mixed $value, Closure $fail) use ($plugin): void {
            foreach (is_array($value) ? $value : [] as $email) {
                if (!is_string($email) || filter_var($email, FILTER_VALIDATE_EMAIL) === false || !$plugin->isDomainAllowed($email)) {
                    $fail(__('filament-scheduled-reports::filament-scheduled-reports.external_invalid', ['email' => is_string($email) ? $email : '']));
                }
            }
        };
    }

    /**
     * @return array<int, string>
     */
    public static function weekdays(): array
    {
        $days = [];

        for ($day = 1; $day <= 7; $day++) {
            $days[$day] = Carbon::now()->startOfWeek()->addDays($day - 1)->locale(app()->getLocale())->isoFormat('dddd');
        }

        return $days;
    }
}
