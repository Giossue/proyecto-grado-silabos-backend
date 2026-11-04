{{-- El título del aviso ya es el asunto; el cuerpo no lo repite y solo agrega qué sílabo, el motivo y qué hacer. --}}
<x-mail::message>
Hola {{ $name }}:

{{ $body }}

<x-mail::button :url="$url">
Abrir el sistema
</x-mail::button>
</x-mail::message>
