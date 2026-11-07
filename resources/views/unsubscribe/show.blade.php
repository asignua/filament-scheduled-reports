<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ __('filament-scheduled-reports::filament-scheduled-reports.unsubscribe_title') }}</title>
    <style>
        body { font-family: system-ui, sans-serif; max-width: 28rem; margin: 4rem auto; padding: 0 1rem; color: #1f2937; }
        button { font: inherit; padding: .6rem 1.2rem; border: 0; border-radius: .4rem; background: #b45309; color: #fff; cursor: pointer; }
        @media (prefers-color-scheme: dark) { body { background: #111827; color: #e5e7eb; } }
    </style>
</head>
<body>
    <h1>{{ __('filament-scheduled-reports::filament-scheduled-reports.unsubscribe_title') }}</h1>
    @if ($done)
        <p>{{ __('filament-scheduled-reports::filament-scheduled-reports.unsubscribe_done') }}</p>
    @else
        <p>{{ __('filament-scheduled-reports::filament-scheduled-reports.unsubscribe_question') }}</p>
        <form method="post" action="{{ $action }}">
            <button type="submit">{{ __('filament-scheduled-reports::filament-scheduled-reports.unsubscribe_button') }}</button>
        </form>
    @endif
</body>
</html>
