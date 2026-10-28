<x-mail::message>
# {{ $title }}

Hola {{ $name }}:

{{ $body }}

<x-mail::button :url="$url">
Abrir el sistema
</x-mail::button>
</x-mail::message>
