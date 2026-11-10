<?php

namespace App\Http\Requests\Eventos;

use App\Http\Controllers\Concerns\ScopesTerritory;
use App\Models\CampaignEvent;
use App\Models\EventAttendee;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Validator;

// Correction of a registered attendee by manage.eventos before it reaches Votantes (an approved or linked one is a
// 422: its data is corrected in the ficha). The AttendeeInput rules (minimum age, cédula, phones...)
// plus the note. Cédula, celular and barrio stay optional only for an attendee that came without them (lists without
// cédula), so an edit never forces inventing them. The event and the attendee are checked before the body, as the
// import requests do: another campaign gets its 404/403 even with an invalid body.
class AttendeeUpdateRequest extends FormRequest
{
    use ScopesTerritory;

    public const LOCKED_STATUSES = ['aprobado', 'vinculado'];

    public const FIELDS = ['nombres', 'apellidos', 'cedula', 'celular', 'fijo', 'fecha_nacimiento', 'direccion', 'barrio', 'nota'];

    private ?EventAttendee $attendee = null;

    public function authorize(): bool
    {
        $id = (string) $this->route('id');
        $attendeeId = (string) $this->route('asistente');
        abort_unless(Str::isUuid($id) && Str::isUuid($attendeeId), 404);
        $event = CampaignEvent::query()->find($id, ['id', 'campaign_id', 'municipio_id']);
        abort_unless($event !== null, 404);
        $this->authorizeCampaign($event->campaign_id, true);
        $this->authorizeGeo($event->municipio_id);
        $this->attendee = EventAttendee::where('event_id', $event->id)->find($attendeeId);
        abort_unless($this->attendee !== null, 404);

        return true;
    }

    protected function prepareForValidation(): void
    {
        $input = AttendeeInput::normalize($this->only(self::FIELDS));
        if (array_key_exists('nota', $input)) {
            $input['nota'] = is_string($input['nota']) ? (trim(preg_replace('/\s+/u', ' ', $input['nota'])) ?: null) : $input['nota'];
        }
        $this->replace($input);
    }

    public function rules(): array
    {
        $rules = collect(AttendeeInput::rules())->except('client_uuid')->all();
        foreach (['cedula', 'celular', 'barrio'] as $field) {
            if ($this->attendee?->{$field} === null) {
                $rules[$field] = array_map(fn ($r) => $r === 'required' ? 'nullable' : $r, $rules[$field]);
            }
        }
        $rules['nota'] = ['nullable', 'string', 'max:255'];

        return $rules;
    }

    // Once approved or linked the person lives in Votantes: the attendee is no longer corrected from the event.
    public function after(): array
    {
        return [function (Validator $validator) {
            if (in_array($this->attendee?->link_status, self::LOCKED_STATUSES, true)) {
                $validator->errors()->add('asistente', 'Este asistente ya está en Votantes: corrija sus datos en su ficha.');
            }
        }];
    }

    public function messages(): array
    {
        return AttendeeInput::messages() + ['nota.max' => 'La nota no puede tener más de 255 caracteres.'];
    }

    public function attributes(): array
    {
        return AttendeeInput::attributes() + ['nota' => 'nota'];
    }
}
