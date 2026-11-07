@use('Asignua\FilamentScheduledReports\Support\Markdown')
<x-mail::message>
# {{ __('filament-scheduled-reports::filament-scheduled-reports.mail_greeting', ['name' => Markdown::escape($recipientName)]) }}

@if ($link)
{{ __('filament-scheduled-reports::filament-scheduled-reports.mail_body_link', ['name' => Markdown::escape($reportName), 'rows' => $rows]) }}

<x-mail::button :url="$link">
{{ __('filament-scheduled-reports::filament-scheduled-reports.mail_download') }}
</x-mail::button>
@else
{{ __('filament-scheduled-reports::filament-scheduled-reports.mail_body', ['name' => Markdown::escape($reportName), 'rows' => $rows]) }}
@endif

@if ($summary !== [])
**{{ __('filament-scheduled-reports::filament-scheduled-reports.mail_filters') }}**

@foreach ($summary as $line)
- {{ Markdown::escape($line) }}
@endforeach
@endif

<x-mail::subcopy>
{{ __('filament-scheduled-reports::filament-scheduled-reports.mail_owner_data') }}
[{{ __('filament-scheduled-reports::filament-scheduled-reports.mail_unsubscribe') }}]({{ $unsubscribeUrl }})
</x-mail::subcopy>
</x-mail::message>
