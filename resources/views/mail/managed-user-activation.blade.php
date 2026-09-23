<x-mail::message>
# Active su cuenta

Hola {{ $name }}:

@if ($careerName)
Tiene una cuenta nueva como **{{ $roleName }}** de la carrera **{{ $careerName }}**.
@else
Tiene una cuenta nueva como **{{ $roleName }}**.
@endif
Para ingresar, defina su contraseña desde este enlace:

<x-mail::button :url="$activationUrl">
Activar mi cuenta
</x-mail::button>

El enlace es personal, de un solo uso y caduca en 24 horas. Si no esperaba este
correo, avise a la coordinación de su carrera.
</x-mail::message>
