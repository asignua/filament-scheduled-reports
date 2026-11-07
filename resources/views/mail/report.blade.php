<x-mail::message>
# {{ __('filament-scheduled-reports::filament-scheduled-reports.mail_greeting', ['name' => $recipientName]) }}

@if ($link)
{{ __('filament-scheduled-reports::filament-scheduled-reports.mail_body_link', ['name' => $reportName, 'rows' => $rows]) }}

<x-mail::button :url="$link">
{{ __('filament-scheduled-reports::filament-scheduled-reports.mail_download') }}
</x-mail::button>
@else
{{ __('filament-scheduled-reports::filament-scheduled-reports.mail_body', ['name' => $reportName, 'rows' => $rows]) }}
@endif

@if ($summary !== [])
**{{ __('filament-scheduled-reports::filament-scheduled-reports.mail_filters') }}**

@foreach ($summary as $line)
- {{ $line }}
@endforeach
@endif

<x-mail::subcopy>
{{ __('filament-scheduled-reports::filament-scheduled-reports.mail_owner_data') }}
[{{ __('filament-scheduled-reports::filament-scheduled-reports.mail_unsubscribe') }}]({{ $unsubscribeUrl }})
</x-mail::subcopy>
</x-mail::message>
