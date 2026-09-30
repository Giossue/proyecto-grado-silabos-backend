<?php

namespace App\Modules\Syllabus\Presentation\Http\Requests;

use App\Modules\Syllabus\Application\Actions\TransitionConvocation;
use App\Modules\Syllabus\Infrastructure\Persistence\Models\Convocation;
use Illuminate\Foundation\Http\FormRequest;

class TransitionConvocationRequest extends FormRequest
{
    /** @var array<string, string> */
    private const ABILITIES = [
        TransitionConvocation::PAUSE => 'pause',
        TransitionConvocation::RESUME => 'resume',
    ];

    public function authorize(): bool
    {
        $convocation = $this->route('convocation');
        $ability = self::ABILITIES[$this->transition()] ?? null;

        return $convocation instanceof Convocation
            && $ability !== null
            && $this->user()?->can($ability, $convocation) === true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [];
    }

    public function transition(): string
    {
        $transition = $this->route('transition');

        return is_string($transition) ? $transition : '';
    }
}
