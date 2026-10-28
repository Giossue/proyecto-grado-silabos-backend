<x-mail::message>
# Sílabos pendientes

Hola {{ $name }}:

Esto espera su atención en el sistema.

@foreach ($careers as $career)
**{{ $career['career'] }}**

@if ($career['review'] > 0)
- {{ $career['review'] }} {{ $career['review'] === 1 ? 'sílabo por revisar' : 'sílabos por revisar' }}
@endif
@if ($career['sign'] > 0)
- {{ $career['sign'] }} {{ $career['sign'] === 1 ? 'sílabo por firmar' : 'sílabos por firmar' }}
@endif

@endforeach
<x-mail::button :url="$url">
Abrir Revisión
</x-mail::button>

Le escribimos una vez al día y solo si tiene algo pendiente.
</x-mail::message>
