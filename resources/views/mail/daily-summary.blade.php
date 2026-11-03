<x-mail::message>
# Sílabos pendientes

Hola {{ $name }}:

Esto espera su atención en el sistema.

@foreach ($lines as $line)
- {{ $line }}
@endforeach

<x-mail::button :url="$url">
{{ $buttonLabel }}
</x-mail::button>
</x-mail::message>
