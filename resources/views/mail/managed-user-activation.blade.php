<x-mail::message>
# Active su cuenta

Hola {{ $name }}:

Se creó una cuenta a su nombre en **{{ config('app.name') }}** con el rol de
**{{ $roleName }}**.

Use el siguiente enlace para verificar su correo y definir una contraseña que solo
usted conozca:

<x-mail::button :url="$activationUrl">
Activar mi cuenta
</x-mail::button>

Este enlace es de un solo uso y caduca en 24 horas. Si no esperaba este mensaje,
avise a la coordinación de su carrera y no lo reenvíe.

Gracias,<br>
{{ config('app.name') }}
</x-mail::message>
