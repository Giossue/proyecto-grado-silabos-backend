<x-mail::message>
# Le damos la bienvenida

Hola {{ $name }}:

@if ($careerName)
Tiene una cuenta como **{{ $roleName }}** de la carrera **{{ $careerName }}**. Para ingresar, elija su contraseña.
@else
Tiene una cuenta como **{{ $roleName }}**. Para ingresar, elija su contraseña.
@endif

<x-mail::button :url="$activationUrl">
Activar mi cuenta
</x-mail::button>

El enlace es personal, de un solo uso y caduca en 24 horas. Si no esperaba este correo, avise a {{ $careerName ? 'la coordinación de su carrera' : 'la administración del sistema' }}.
</x-mail::message>
