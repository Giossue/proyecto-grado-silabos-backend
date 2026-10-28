<x-mail::message>
# {{ $title }}

Hola {{ $name }}:

{{ $body }}

<x-mail::button :url="$url">
Abrir el sistema
</x-mail::button>

Solo le escribimos cuando hay algo que usted debe hacer. El resto de avisos los ve en la
campana del sistema.
</x-mail::message>
