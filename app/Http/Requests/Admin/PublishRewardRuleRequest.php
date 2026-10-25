<?php

namespace App\Http\Requests\Admin;

use App\Models\RewardRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;

/**
 * A new version of an existing reward rule. The platform and event a rule listens to are what the code is wired to
 * and are not editable; what the rule pays, its caps and whether it needs approval are.
 */
class PublishRewardRuleRequest extends FormRequest
{
    /** The rule being changed, resolved once from the route. */
    public ?RewardRule $latest = null;

    public function authorize(): bool
    {
        return $this->user()?->can('manage-points') ?? false;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'based_on_version' => ['required', 'integer'],
            'points' => ['required', 'integer', 'min:0', 'max:10000'],
            'min_points' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'max_points' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'daily_cap_points' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'max_awards_per_contribution' => ['required', 'integer', 'min:1', 'max:100'],
            'requires_approval' => ['boolean'],
            'active' => ['boolean'],
            'reason' => ['required', 'string', 'min:10', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return ['reason.min' => 'Say why in at least 10 characters: this is kept with the version for good.'];
    }

    /** Errors stay with the rule being edited, and the page returns to that rule's card. */
    protected function failedValidation(Validator $validator): void
    {
        $key = (string) $this->route('key');

        throw (new ValidationException($validator))
            ->errorBag(self::errorBag($key))
            ->redirectTo($this->getRedirectUrl().'#rule-'.str_replace('.', '-', $key));
    }

    public static function errorBag(string $key): string
    {
        return 'rule:'.$key;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'requires_approval' => $this->boolean('requires_approval'),
            'active' => $this->boolean('active'),
            'min_points' => $this->input('min_points') === '' ? null : $this->input('min_points'),
            'max_points' => $this->input('max_points') === '' ? null : $this->input('max_points'),
            'daily_cap_points' => $this->input('daily_cap_points') === '' ? null : $this->input('daily_cap_points'),
        ]);
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $latest = $this->latest();

            if ((int) $this->input('based_on_version') !== $latest->version) {
                $validator->errors()->add('based_on_version', "Version {$latest->version} was published while you were editing. Review it and try again.");

                return;
            }

            $min = $this->input('min_points');
            $max = $this->input('max_points');
            $points = (int) $this->input('points');

            if (($min === null) !== ($max === null)) {
                $validator->errors()->add('min_points', 'Give both ends of the range, or neither.');
            } elseif ($min !== null && (int) $min > (int) $max) {
                $validator->errors()->add('min_points', 'The smallest award cannot be more than the largest.');
            } elseif ($min !== null && ($points < (int) $min || $points > (int) $max)) {
                $validator->errors()->add('points', 'The default points must sit inside the range.');
            }

            if ($this->boolean('active') && $points < 1 && $min === null) {
                $validator->errors()->add('points', 'A rule that is switched on must pay at least 1 point. Switch it off instead.');
            }

            if ($validator->errors()->isEmpty() && $this->changes() === []) {
                $validator->errors()->add('reason', 'Nothing differs from the current version, so there is nothing to publish.');
            }
        }];
    }

    /**
     * The fields that differ from the version being replaced.
     *
     * @return array<string, mixed>
     */
    public function changes(): array
    {
        $latest = $this->latest();
        $new = $this->newValues();
        $changes = [];

        foreach ($new as $field => $value) {
            $current = is_bool($value) ? (bool) $latest->{$field} : ($latest->{$field} === null ? null : (int) $latest->{$field});

            if ($current !== $value) {
                $changes[$field] = $value;
            }
        }

        return $changes;
    }

    /** @return array<string, mixed> */
    public function newValues(): array
    {
        $int = fn (string $key): ?int => $this->input($key) === null ? null : (int) $this->input($key);

        return [
            'points' => (int) $this->input('points'),
            'min_points' => $int('min_points'),
            'max_points' => $int('max_points'),
            'daily_cap_points' => $int('daily_cap_points'),
            'max_awards_per_contribution' => (int) $this->input('max_awards_per_contribution'),
            'requires_approval' => $this->boolean('requires_approval'),
            'active' => $this->boolean('active'),
        ];
    }

    public function latest(): RewardRule
    {
        return $this->latest ??= RewardRule::latestVersion((string) $this->route('key')) ?? abort(404);
    }
}
