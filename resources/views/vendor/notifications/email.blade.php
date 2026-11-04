<x-mail::message>
{{-- Heading: el título dice qué hacer y no repite el asunto ni el saludo vacío «Hola:». --}}
<?php
    $heading = ! empty($greeting) ? $greeting : match (true) {
        $level === 'error' => __('Whoops!'),
        ($actionText ?? null) === __('Reset Password') => __('Choose a new password'),
        default => null,
    };
?>
@if ($heading)
# {{ $heading }}

@endif
{{-- Intro Lines --}}
@foreach ($introLines as $line)
{{ $line }}

@endforeach

{{-- Action Button --}}
@isset($actionText)
<?php
    $color = match ($level) {
        'success', 'error' => $level,
        default => 'primary',
    };
?>
<x-mail::button :url="$actionUrl" :color="$color">
{{ $actionText }}
</x-mail::button>
@endisset

{{-- Outro Lines --}}
@foreach ($outroLines as $line)
{{ $line }}

@endforeach

{{-- Subcopy --}}
@isset($actionText)
<x-slot:subcopy>
@lang(
    "If you're having trouble clicking the \":actionText\" button, copy and paste the URL below\n".
    'into your web browser:',
    [
        'actionText' => $actionText,
    ]
) <span class="break-all">[{{ $displayableActionUrl }}]({{ $actionUrl }})</span>
</x-slot:subcopy>
@endisset
</x-mail::message>
