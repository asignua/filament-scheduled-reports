@use('Asignua\FilamentScheduledReports\Support\Markdown')
<x-mail::message>
# {{ Markdown::escape($title) }}

**{{ Markdown::escape($reportName) }}**

{{ Markdown::escape($detail) }}
@if ($unsubscribeUrl)

<x-mail::subcopy>
[{{ __('filament-scheduled-reports::filament-scheduled-reports.mail_unsubscribe') }}]({{ $unsubscribeUrl }})
</x-mail::subcopy>
@endif
</x-mail::message>
