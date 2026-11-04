{{-- Resumen diario de Docente, Biblioteca y Coordinación: una línea por carrera y un botón. --}}
<x-mail::message>
# Pendientes de hoy

Hola {{ $name }}:

@foreach ($lines as $line)
- {{ $line }}
@endforeach

<x-mail::button :url="$url">
{{ $buttonLabel }}
</x-mail::button>
</x-mail::message>
