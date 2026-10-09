<?php

namespace App\Modules\Configuration\Domain;

use App\Modules\Configuration\Application\TemplateDocumentResolver;
use App\Modules\Configuration\Application\TemplateVariables;
use Illuminate\Validation\ValidationException;

final class FieldDocument
{
    /**
     * @return array<string, mixed>
     */
    public static function empty(): array
    {
        return ['type' => 'doc', 'content' => [['type' => 'paragraph']]];
    }

    /**
     * @return array<string, mixed>
     */
    public static function normalize(mixed $value, string $attribute = 'content'): array
    {
        if (! FreeText::isDocument($value)) {
            throw ValidationException::withMessages([$attribute => 'El contenido debe ser un documento de texto, listas o tablas.']);
        }
        try {
            return TemplateDocument::normalize($value, array_keys(TemplateVariables::definitions()));
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages([$attribute => collect($exception->errors())->flatten()->all()]);
        }
    }

    /**
     * @param  array<string, mixed>  $document
     * @param  array<string, string>  $variables
     * @return array<string, mixed>
     */
    public static function resolve(array $document, array $variables): array
    {
        return TemplateDocumentResolver::resolve($document, [], $variables);
    }

    public static function isEmpty(mixed $value): bool
    {
        return ! FreeText::isDocument($value) || trim(FreeText::plainText($value)) === '';
    }
}
