<x-mail::message>
# Sílabos pendientes

Hola {{ $name }}:

Esto espera su atención en el sistema.

@foreach ($careers as $career)
**{{ $career['career'] }}**

@if ($career['review'] > 0)
- {{ $career['review'] }} {{ $career['review'] === 1 ? 'sílabo por revisar' : 'sílabos por revisar' }}
@endif

@endforeach
<x-mail::button :url="$url">
Abrir Revisión
</x-mail::button>
</x-mail::message>
