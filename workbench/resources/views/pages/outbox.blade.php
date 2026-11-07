<x-filament-panels::page>
    <x-filament::section heading="Sent mail">
        <div class="outbox">
            @forelse ($this->messages() as $message)
                <div class="outbox-message" style="padding: .5rem 0; border-bottom: 1px solid rgba(128,128,128,.25)">
                    <div><strong>{{ $message['subject'] }}</strong> <span style="opacity:.6">→ {{ $message['to'] }} · {{ $message['at'] }}</span></div>
                    @if ($message['attachment'] !== '')
                        <div style="opacity:.8">Attachment: {{ $message['attachment'] }}</div>
                    @endif
                    @if ($message['unsubscribe'] !== '')
                        <div><a class="outbox-unsubscribe" href="{{ $message['unsubscribe'] }}" style="text-decoration: underline">Unsubscribe link from the message</a></div>
                    @endif
                </div>
            @empty
                <p style="opacity:.7">Nothing sent yet.</p>
            @endforelse
        </div>
    </x-filament::section>
</x-filament-panels::page>
