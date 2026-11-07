@use('Asignua\FilamentScheduledReports\Support\Markdown')
<x-mail::message>
# {{ Markdown::escape($title) }}

**{{ Markdown::escape($reportName) }}**

{{ Markdown::escape($detail) }}
</x-mail::message>
